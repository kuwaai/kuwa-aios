## Skyscope Quick Installation for Linux/docker

Download the script or the executable file, run it, and follow its steps to have your own Skyscope!

Download and run sudo [build.sh](../docker/build.sh), or invoke the following command to automatically install Docker, CUDA, and Skyscope. You may need to reboot after installing CUDA. Before finishing installation, you will be asked to set your administration passwords for your Skyscope and database. After installation, it will invoke [run.sh](../docker/run.sh.sample) to start the system and you can log in with admin@localhost. Enjoy!
```bash!
bash -c "$(curl -fsSL https://raw.githubusercontent.com/skyscopeai/skyscope-aios/main/docker/build.sh)"
```
