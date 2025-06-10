# Docker Advanced Topic

### Start with Debugging Mode
By default, the Docker version will not display any error messages on the Multi-Chat web front end. If you encounter an error, you can cancel the annotation before `# "dev"` in `./run.sh`, and then re-execute the following command to start the debugging mode.
```sh
./run.sh
```

### Running Multiple Executors
Each Executor's setting is written in the corresponding YAML file under the `docker/compose` directory (gemini.yaml, chatgpt.yaml, huggingface.yaml, llamacpp.yaml, ...). Please reference these files and expand them according to your needs. You may need to consult the [Executor documentation](../src/executor/README_TW.md).

Add the required YAML settings file to the `confs` array in `./run.sh`. After setting up the files, you can initiate the entire system using the following command:
```sh
./run.sh
```

### Force Upgrade
If your database is accidentally lost or corrupted, you can reset the database by forcibly updating it  
Please make sure the system is running, then use the following command to force upgrade the database  
```sh
docker exec -it kuwa-multi-chat-1 docker-entrypoint force-upgrade
```

### Building Docker Images from Source Code

Since version 0.3.4, Skyscope Docker Images are downloaded pre-built from Docker Hub by default. To build images from source code, follow these steps:

1. Ensure the `.git` directory is present within the `genai-os` directory.
2. Enable the containerd image store for [multi-platform builds](https://docs.docker.com/build/building/multi-platform/#enable-the-containerd-image-store)
    Add the following configuration to your `/etc/docker/daemon.json` file:

    ```json
    {
      "features": {
        "containerd-snapshotter": true
      }
    }
    ```
    Restart the Docker daemon after saving the changes:
    ```sh
    sudo systemctl restart docker
    ```
3. **Build the Skyscope images using the following command:**
    ```sh
    sudo ./run.sh build
    ```

This command will create the following images: 

- `kuwaai/model-executor`
- `kuwaai/multi-chat`
- `kuwaai/kernel`
- `kuwaai/multi-chat-web`

### Setting Up HTTPS Service with Let's Encrypt

Starting with version 0.4.0, the Docker version of Skyscope offers automatic HTTPS certificate acquisition using Let's Encrypt. To set up your HTTPS service:

1. Ensure your domain name's DNS records are properly configured and your firewall allows traffic on ports 80 and 443.
2. In the `docker/.env` file, set the `DOMAIN_NAME` variable to your domain (e.g., `example.com`).
3. Modify the `docker/run.sh` script by adding `"https-auto"` to the `confs` array.
4. Run the `./run.sh` script. (You may need to run `./run.sh build web` first if the image isn't up-to-date).
5. The certificate acquisition process will begin automatically.
6. Watch for the following log message indicating successful configuration:
   ```
   letsencrypt-companion-1  | Reloading nginx (using separate container kuwa-web-1)...
   ```
7. Due to a known issue, you'll need to manually terminate the `run.sh` script and restart it to prevent the "web" container from repeatedly restarting.
8. Your site is now accessible via HTTPS at `https://example.com/`.

### Resetting the Database Password

If you accidentally delete the `.db-password` file and forget your database password, you can use the following command to reset it:

```sh
docker exec kuwa-db-1 psql -U kuwa -d kuwa-genai-os -c "ALTER USER kuwa PASSWORD '<new-password>';"
```

**Remember to replace `<new-password>` with your desired new password.**