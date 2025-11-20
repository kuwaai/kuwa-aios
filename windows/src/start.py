import os, subprocess, shutil, requests, time, sys, re, psutil, concurrent.futures, threading, random, glob

MAX_WORKERS = 10
MAX_RETRIES = 5
INITIAL_DELAY = 2
BOT_IMPORT_FAILURE_KEYWORDS = ("cannot be imported", "does not exist")

processes = []
base_dir = os.path.abspath(os.path.join(os.path.dirname(__file__), '..'))
kuwa_root = os.getenv("KUWA_ROOT", os.path.join(base_dir, "kuwa_root"))
os.makedirs(os.path.join(base_dir, "logs"), exist_ok=True)
log_path = os.path.join(base_dir, "logs", "start.log")

def wait_and_remove(path, retry_interval=0.5, timeout=60):
    start_time = time.time()
    while os.path.exists(path):
        try:
            os.remove(path)
            print(f"Removed old log file: {path}")
            return
        except PermissionError:
            if time.time() - start_time > timeout:
                raise TimeoutError(f"Timed out waiting to delete: {path}")
            time.sleep(retry_interval)
wait_and_remove(log_path)


class Logger:
    def __init__(self, stream, path):
        self.stream = stream
        self.path = path
        self.lock = threading.Lock()
    def write(self, msg):
        self.stream.write(msg)
        self.stream.flush()
        with self.lock:
            with open(self.path, 'a', encoding='utf-8') as f:
                f.write(msg)
                f.flush()
    def flush(self):
        self.stream.flush()

sys.stdout = Logger(sys.stdout, log_path)
sys.stderr = Logger(sys.stderr, log_path)

def logged_input(prompt=""):
    if prompt:
        print(prompt, end='', flush=True)
    line = sys.__stdin__.readline()
    if not line:
        raise EOFError
    print(line.rstrip('\n'))
    return line.rstrip('\n')
input = logged_input

def cleanup_temp_bats():
    """
    Scans the executors directory and removes temporary run batch files.
    This function is called at startup, after background processes are launched, and at shutdown.
    """
    print("--- Cleaning up temporary run batch files ---")
    executors_dir = os.path.join(base_dir, "executors")
    if not os.path.isdir(executors_dir):
        return
    
    for dirpath, _, filenames in os.walk(executors_dir):
        for filename in filenames:
            if filename.startswith("temp_run_") and filename.endswith(".bat"):
                temp_bat_path = os.path.join(dirpath, filename)
                try:
                    os.remove(temp_bat_path)
                    print(f"Removed temporary file: {temp_bat_path}")
                except OSError as e:
                    print(f"Error removing temporary file {temp_bat_path}: {e}")

def run_and_log(cmd, cwd=None):
    """
    Executes a command, logs its output to stdout in real-time, and waits for it to complete.
    Ensures all output is captured by the Logger.
    """
    print(f"--- Running command: {cmd} in {cwd or '.'} ---")
    try:
        proc = subprocess.Popen(
            cmd,
            cwd=cwd,
            shell=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.STDOUT,
            text=True,
            encoding='utf-8',
            errors='replace',
            bufsize=1
        )
        for line in proc.stdout:
            # print() will be handled by our Logger class
            print(line, end='', flush=True)

        proc.wait()
        if proc.returncode != 0:
            print(f"--- Command '{cmd}' finished with non-zero exit code: {proc.returncode} ---")
        else:
            print(f"--- Command '{cmd}' finished successfully ---")
        return proc.returncode
    except Exception as e:
        print(f"--- EXCEPTION while running command '{cmd}': {e} ---")
        return -1

def run_background(cmd, cwd=None):
    print(f"--- Starting background process: {cmd} ---")
    env = os.environ.copy()

    proc = subprocess.Popen(
        cmd,
        cwd=cwd,
        shell=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.STDOUT,
        text=True,
        encoding="utf-8",
        errors='replace',
        bufsize=1,
        creationflags=subprocess.CREATE_NEW_PROCESS_GROUP,
        env=env
    )

    processes.append(proc)

    def log_output():
        try:
            for line in proc.stdout:
                print(line, end='')
        except Exception as e:
            print(f"--- EXCEPTION in background logger for '{cmd}': {e} ---")

    threading.Thread(target=log_output, daemon=True).start()
    return proc

def terminate_proc(proc, current_pid):
    try:
        if proc.pid == current_pid: return
        if proc.info.get('exe', '').startswith(os.path.abspath(os.path.join(base_dir, '..'))):
            print(f"Terminating process {proc.pid}: {proc.info.get('name', 'N/A')}")
            proc.terminate()
            try:
                proc.wait(timeout=2)
            except psutil.TimeoutExpired:
                print(f"Force killing {proc.pid}")
                proc.kill()
    except (psutil.NoSuchProcess, psutil.AccessDenied):
        pass

def hard_exit(restart):
    print("--- Initiating hard exit ---")
    
    cleanup_temp_bats()

    services = [
        ("Redis", "redis-cli.exe shutdown", os.path.join(base_dir, "packages", os.environ.get("redis_folder") or "redis")),
        ("Laravel worker", "php artisan worker:stop", os.path.abspath("../src/multi-chat"))
    ]

    http_server_runtime = os.environ.get("HTTP_Server_Runtime", "nginx")
    if http_server_runtime == "nginx":
        services.insert(0, ("Nginx", r'.\nginx.exe -s quit', os.path.join(base_dir, "packages", os.environ.get("nginx_folder") or "nginx")))

    for name, cmd, cwd in services:
        print(f"Issuing shutdown for {name}...")
        run_and_log(cmd, cwd=cwd)

    current_pid = os.getpid()
    current_proc = psutil.Process(current_pid)
    
    with concurrent.futures.ThreadPoolExecutor(max_workers=10) as executor:
        all_procs = [p for p in psutil.process_iter(['pid', 'exe', 'name']) if p.pid != current_pid]
        futures = [executor.submit(terminate_proc, p, current_pid) for p in all_procs]
        concurrent.futures.wait(futures)

    if restart:
        print("--- Restarting application... ---")
        subprocess.Popen(["start.bat"], shell=True, creationflags=subprocess.CREATE_NEW_PROCESS_GROUP)

    print(f"--- Terminating current Python process (PID {current_pid}) ---")
    current_proc.terminate()
    os._exit(0)

def extract_packages():
    zip_path = os.path.abspath("../scripts/windows-setup-files/package.zip")
    multi_chat = os.path.abspath("../src/multi-chat")
    env_file = os.path.join(multi_chat, ".env")
    
    if not os.path.exists(env_file):
        shutil.copyfile(os.path.join(multi_chat, ".env.dev"), env_file)
    
    shutil.rmtree(os.path.join(multi_chat, "storage", "framework", "cache"), ignore_errors=True)
    if os.path.exists(zip_path):
        print("--- Initializing filesystem and application setup ---")
        for f in ["bin", "database", "custom", "bootstrap/bot"]:
            os.makedirs(os.path.join(kuwa_root, f), exist_ok=True)
        shutil.copytree("../src/bot/init", os.path.join(kuwa_root, "bootstrap/bot"), dirs_exist_ok=True)
        shutil.copytree("../src/tools", os.path.join(kuwa_root, "bin"), dirs_exist_ok=True)
        shutil.rmtree(os.path.join(kuwa_root, "bin", "test"), ignore_errors=True)
        setup_commands = [
            "php artisan key:generate --force",
            "php artisan db:seed --class=InitSeeder --force",
            "php artisan migrate --force",
            "php artisan storage:link",
            "php ../../windows/packages/composer.phar dump-autoload --optimize",
            "php artisan route:cache",
            "php artisan view:cache",
            "php artisan optimize",
            "npm.cmd run build",
            "php artisan config:cache",
            "php artisan config:clear"
        ]
        for cmd in setup_commands:
            run_and_log(cmd, cwd=multi_chat)
        os.remove(zip_path)
    if os.path.exists(os.path.abspath("init.txt")):
        print("--- Processing init.txt for admin user creation and auto-login ---")
        with open("init.txt") as f:
            config_data = dict(line.strip().split("=", 1) for line in f if "=" in line)
        username = config_data.get("username", "")
        password = config_data.get("password", "")
        autologin = config_data.get("autologin", "").lower() == "true"

        name = username.split("@")[0] if "@" in username else ""

        if name and username and password:
            run_and_log(
                f"php artisan create:admin-user --name={name} --email={username} --password={password}",
                cwd=multi_chat
            )

        if autologin and username:
            with open(env_file, 'r', encoding="utf-8") as f:
                content = f.read()
            content = re.sub(r'^APP_AUTO_EMAIL=.*$', '', content, flags=re.MULTILINE).strip()
            content += f"\nAPP_AUTO_EMAIL={username}\n"
            with open(env_file, 'w', encoding="utf-8") as f:
                f.write(content)
            for cmd in [
                "php artisan config:clear",
                "php artisan cache:clear",
                "php artisan config:cache",
                ]:
                run_and_log(cmd, cwd=multi_chat)
        os.remove(os.path.abspath("init.txt"))

    composer_bat = os.path.join(base_dir, "packages", "composer.bat")
    if not os.path.exists(composer_bat):
        with open(composer_bat, 'w') as f:
            f.write('php "%~dp0composer.phar" %*\n')

def extract_executor_access_code(path):
    with open(path) as f:
        for line in f:
            if line.lower().startswith('set '):
                m = re.match(r'set\s+"?EXECUTOR_ACCESS_CODE\s*=\s*([^"\s]*)"?', line, re.I)
                if m: return m.group(1).strip()
    return None

def get_bot_access_code(file_path):
    try:
        with open(file_path, 'r', encoding='utf-8', errors='ignore') as f:
            for line in f:
                if line.strip().upper().startswith('KUWABOT BASE'):
                    match = re.search(r'KUWABOT\s+base\s+(.*)', line, re.IGNORECASE)
                    if match:
                        access_code = match.group(1).strip().strip('"\'')
                        return access_code
    except Exception as e:
        print(f"Could not read or parse bot file {file_path}: {e}")
    return None

def start_servers():
    redis_path = os.path.join(base_dir, "packages", os.environ.get("redis_folder", "redis"))
    rdb_path = os.path.join(redis_path, "dump.rdb")
    if os.path.exists(rdb_path):
        os.remove(rdb_path)
    run_background("redis-server.exe redis.conf", cwd=redis_path)

    web_path = os.path.abspath("../src/multi-chat")
    run_and_log('php artisan web:config --settings="updateweb_path=%PATH%"', cwd=web_path)
    run_background("php artisan worker:start 10", cwd=web_path)

    kernel_path = os.path.abspath("../src/kernel")
    pickle_path = os.path.join(kernel_path, "records.pickle")
    if os.path.exists(pickle_path):
        os.remove(pickle_path)
    run_background("kuwa-kernel", cwd=kernel_path)

    print("Waiting for kernel to be available...")
    while True:
        try:
            requests.get("http://127.0.0.1:9000", timeout=1)
            print("Kernel is up.")
            break
        except requests.ConnectionError:
            time.sleep(1)

    executors_dir = os.path.join(base_dir, "executors")
    folder_paths = [os.path.join(executors_dir, f) for f in os.listdir(executors_dir) if os.path.isdir(os.path.join(executors_dir, f))]

    def process_folder(folder_path, max_init_delay_sec:int=10):
        time.sleep(random.uniform(0, max_init_delay_sec))
        run_bat_path = os.path.join(folder_path, "run.bat")
        init_bat_path = os.path.join(folder_path, "init.bat")
        artisan_commands = []
        exclude_code = None
        
        try:
            if os.path.exists(init_bat_path) and not os.path.exists(run_bat_path):
                run_and_log("init.bat quick", cwd=folder_path)
            
            if os.path.exists(run_bat_path):
                other_commands = []
                with open(run_bat_path, 'r', encoding='utf-8') as f:
                    for line in f:
                        if "php artisan" in line.lower():
                            artisan_commands.append(line.strip())
                        else:
                            other_commands.append(line)
                
                if other_commands:
                    temp_bat_name = f"temp_run_{random.randint(1000,9999)}.bat"
                    temp_bat_path = os.path.join(folder_path, temp_bat_name)
                    try:
                        with open(temp_bat_path, 'w', encoding='utf-8') as temp_f:
                            temp_f.write("@echo off\n") 
                            temp_f.write("\n".join(other_commands))
                        
                        run_background(temp_bat_name, cwd=folder_path)
                    except Exception as e:
                        print(f"Error preparing to launch background task in {folder_path}: {e}")

                code = extract_executor_access_code(run_bat_path)
                if code:
                    exclude_code = f"--exclude={code}"
                    
        except Exception as e:
            print(f"Error processing {folder_path}: {e}")
            
        return (exclude_code, artisan_commands)

    exclude_args = []
    all_artisan_commands = []
    with concurrent.futures.ThreadPoolExecutor() as executor:
        futures = {executor.submit(process_folder, path): path for path in folder_paths}
        for future in concurrent.futures.as_completed(futures):
            try:
                exclude_code, artisan_commands = future.result()
                if exclude_code: exclude_args.append(exclude_code)
                if artisan_commands: all_artisan_commands.extend(artisan_commands)
            except Exception as e:
                print(f"A task in process_folder generated an exception: {e}")
    
    excluded_access_codes = {arg[10:] for arg in exclude_args if arg.startswith('--exclude=') and len(arg) > 10}
    if excluded_access_codes:
        print(f"Bots to be initialized separately: {', '.join(excluded_access_codes)}")
    
    if all_artisan_commands:
        print("--- Executing collected artisan commands sequentially ---")
        for command in all_artisan_commands:
            run_and_log(command, cwd=web_path)
        print("--- Finished executing artisan commands ---")

    if exclude_args:
        run_and_log(f"php artisan model:prune --force {' '.join(exclude_args)}", cwd=web_path)

    http_server_runtime = os.environ.get("HTTP_Server_Runtime", "nginx")
    if (http_server_runtime == "apache"):
        apache_folder = os.path.join("Apache_" + os.environ.get("apache_folder", "apache"), "Apache24")
        apache_htdocs = os.path.join(base_dir, "packages", apache_folder, "htdocs")
        if os.path.exists(apache_htdocs):
            try: os.unlink(apache_htdocs)
            except OSError: shutil.rmtree(apache_htdocs, ignore_errors=True)
        subprocess.run(f'mklink /j "{apache_htdocs}" "{os.path.abspath("../src/multi-chat/public")}"', shell=True, check=True)
        
        fcgid_path = os.path.join(base_dir, "packages", apache_folder, "conf", 'extra', 'httpd-fcgid.conf')
        with open(fcgid_path, encoding='utf-8') as f:
            t = f.read()
        with open(fcgid_path, 'w', encoding='utf-8') as f:
            f.write(re.sub(r'(FcgidWrapper\s+")([^"]*)("\s+\.php)', lambda m: m.group(1) + os.path.join(base_dir, "packages", os.environ.get("php_folder", "php"), 'php-cgi.exe').replace("\\","/") + m.group(3), t))
    
        run_background("httpd.exe", cwd=os.path.join(base_dir, "packages", apache_folder, "bin"))
        print("Apache started!")
    elif (http_server_runtime == "nginx"):
        php_path = os.path.join(base_dir, "packages", os.environ.get("php_folder", "php"))
        for port in range(9101, 9111):
            run_background(f"php-cgi.exe -b 127.0.0.1:{port}", cwd=php_path)
        run_background(f"php-cgi.exe -b 127.0.0.1:9123", cwd=php_path)
        nginx_folder = os.environ.get("nginx_folder", "nginx")
        nginx_html = os.path.join(base_dir, "packages", nginx_folder, "html")
        if os.path.exists(nginx_html):
            if os.path.islink(nginx_html):
                os.unlink(nginx_html)
            else:
                shutil.rmtree(nginx_html, ignore_errors=True)
        run_and_log(f'mklink /j "{nginx_html}" "{os.path.abspath("../src/multi-chat/public")}"')
        run_background("nginx.exe", cwd=os.path.join(base_dir, "packages", nginx_folder))
        print("Nginx started!")

    run_and_log("php artisan model:reset-health", cwd=web_path)
    time.sleep(4)
    
    def import_bot(bot_file_path):
        name = os.path.basename(bot_file_path)
        command = f'php artisan bot:import "{bot_file_path}"'
        
        for attempt in range(MAX_RETRIES):
            print(f"--- Importing {name} (Attempt {attempt + 1}/{MAX_RETRIES}) ---")
            try:
                result = subprocess.run(
                    command, cwd=web_path, shell=True, capture_output=True,
                    text=True, encoding="utf-8", errors="replace"
                )
                output = result.stdout + result.stderr
                print(output, end='')

                is_success = result.returncode == 0 and not any(k in output.lower() for k in BOT_IMPORT_FAILURE_KEYWORDS)
                
                if is_success:
                    print(f"--- SUCCESS: Finished import for {name} ---")
                    return f"Success: {name}"
                else:
                    print(f"--- FAILED: Import for {name} (Exit Code: {result.returncode}) ---")
            except Exception as e:
                print(f"--- EXCEPTION while importing {name}: {e} ---")

            if attempt < MAX_RETRIES - 1:
                delay = INITIAL_DELAY * (2 ** attempt) + random.uniform(0, 1)
                print(f"--- Retrying {name} in {delay:.2f} seconds... ---")
                time.sleep(delay)
            else:
                print(f"--- GIVING UP on {name} after {MAX_RETRIES} attempts. ---")
                return f"Failed: {name}"

    print("--- Preparing to import bots... ---")
    bots_dir = os.path.join(kuwa_root, "bootstrap", "bot")
    if os.path.isdir(bots_dir):
        all_bot_files = glob.glob(os.path.join(bots_dir, '*.*'))
        bot_files_to_import = [p for p in all_bot_files if get_bot_access_code(p) in excluded_access_codes]
        if bot_files_to_import:
            with concurrent.futures.ThreadPoolExecutor(max_workers=MAX_WORKERS) as executor:
                list(executor.map(import_bot, bot_files_to_import))
        else:
            print("No bot files to import.")
    else:
        print(f"Bot directory not found, skipping import: {bots_dir}")

    cleanup_temp_bats()

    print("--- System initialized. Press Ctrl+C or type a command. ---")
    run_and_log('start http://127.0.0.1')

def command_loop():
    while True:
        try:
            cmd = input("Enter a command (stop, seed, hf login, reload): ").strip().lower()
            if cmd == "stop":
                hard_exit(False)
            elif cmd == "seed":
                seed_path = os.path.abspath("../src/multi-chat/executables/bat")
                run_and_log("AdminSeeder.bat", cwd=seed_path)
            elif cmd == "hf login":
                run_and_log("huggingface-cli.exe login")
            elif cmd == "reload":
                hard_exit(True)
            else:
                print("Unknown command.")
        except (KeyboardInterrupt, EOFError):
            print("\nExit signal received.")
            hard_exit(False)

if __name__ == "__main__":
    cleanup_temp_bats()

    extract_packages()
    start_servers()
    command_loop()