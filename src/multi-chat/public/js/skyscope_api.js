class SkyscopeClient {
    constructor(authToken, baseUrl = "http://localhost") {
        if (!authToken) {
            throw new Error("You must provide an authToken!");
        }
        this.authToken = authToken;
        this.baseUrl = baseUrl;
    }

    async createBaseModel(name, accessCode, options = {}, callbacks = {}) {
        const url = `${this.baseUrl}/api/user/create/base_model`;
        const headers = {
            "Content-Type": "application/json",
            "Authorization": `Bearer ${this.authToken}`,
        };
        const requestBody = {
            name,
            access_code: accessCode,
            ...options
        };

        const response = await this._makeRequest(url, "POST", headers, JSON.stringify(requestBody), callbacks);
        return response;
    }

    async listBaseModels(callbacks = {}) {
        const url = `${this.baseUrl}/api/user/read/models`;
        const headers = {
            "Content-Type": "application/json",
            "Authorization": `Bearer ${this.authToken}`,
        };

        const response = await this._makeRequest(url, "GET", headers, null, callbacks);
        return response;
    }

    async listBots(callbacks = {}) {
        const url = `${this.baseUrl}/api/user/read/bots`;
        const headers = {
            "Content-Type": "application/json",
            "Authorization": `Bearer ${this.authToken}`,
        };

        const response = await this._makeRequest(url, "GET", headers, null, callbacks);
        return response;
    }

    async listRooms(callbacks = {}) {
        const url = `${this.baseUrl}/api/user/read/rooms`;
        const headers = {
            "Content-Type": "application/json",
            "Authorization": `Bearer ${this.authToken}`,
        };

        const response = await this._makeRequest(url, "GET", headers, null, callbacks);
        return response;
    }

    async listCloud(path = '', callbacks = {}) {
        const url = `${this.baseUrl}/api/user/read/cloud${path}`;
        const headers = {
            "Content-Type": "application/json",
            "Authorization": `Bearer ${this.authToken}`,
        };

        const response = await this._makeRequest(url, "GET", headers, null, callbacks);
        return response;
    }

    async createUsers(users, callbacks = {}) {
        const url = `${this.baseUrl}/api/user/create/user`;
        const headers = {
            "Content-Type": "application/json",
            "Authorization": `Bearer ${this.authToken}`,
        };
        const requestBody = {
            users: users.map(userInstance => userInstance.getUser())
        };

        const response = await this._makeRequest(url, "POST", headers, JSON.stringify(requestBody), callbacks);
        return response;
    }

    async createRoom(bot_ids, callbacks = {}) {
        const url = `${this.baseUrl}/api/user/create/room`;
        const headers = {
            "Content-Type": "application/json",
            "Authorization": `Bearer ${this.authToken}`,
        };
        const requestBody = {
            llm: bot_ids
        };

        const response = await this._makeRequest(url, "POST", headers, JSON.stringify(requestBody), callbacks);
        return response;
    }

    async uploadFile(file, callbacks = {}) {
        const url = `${this.baseUrl}/api/user/upload/file`;
        const headers = {
            "Authorization": `Bearer ${this.authToken}`,
        };
        const formData = new FormData();
        formData.append('file', file);

        const response = await this._makeRequest(url, "POST", headers, formData, callbacks);
        return response;
    }

    async deleteRoom(room_id, callbacks = {}) {
        const url = `${this.baseUrl}/api/user/delete/room/`;
        const headers = {
            "Content-Type": "application/json",
            "Authorization": `Bearer ${this.authToken}`,
        };
        const requestBody = {
            id: room_id
        };

        const response = await this._makeRequest(url, "DELETE", headers, JSON.stringify(requestBody), callbacks);
        return response;
    }

    async deleteMessage(message_id, callbacks = {}) {
        const url = `${this.baseUrl}/api/user/delete/room/message`;
        const headers = {
            "Content-Type": "application/json",
            "Authorization": `Bearer ${this.authToken}`,
        };
        const requestBody = {
            id: message_id
        };

        const response = await this._makeRequest(url, "DELETE", headers, JSON.stringify(requestBody), callbacks);
        return response;
    }

    async deleteCloud(path = '', callbacks = {}) {
        const url = `${this.baseUrl}/api/user/delete/cloud${path}`;
        const headers = {
            "Content-Type": "application/json",
            "Authorization": `Bearer ${this.authToken}`,
        };

        const response = await this._makeRequest(url, "DELETE", headers, null, callbacks);
        return response;
    }

    async createBot(llmAccessCode, botName, options = {}, callbacks = {}) {
        const url = `${this.baseUrl}/api/user/create/bot`;
        const headers = {
            "Content-Type": "application/json",
            "Authorization": `Bearer ${this.authToken}`,
        };
        const requestBody = {
            llm_access_code: llmAccessCode,
            bot_name: botName,
            visibility: 3, // Default visibility
            ...options
        };

        const response = await this._makeRequest(url, "POST", headers, JSON.stringify(requestBody), callbacks);
        return response;
    }

    async *chatCompleteAsync(model, messages = [], options = {}) {
        // This is streaming method
        const url = `${this.baseUrl}/v1.0/chat/completions`;
        const headers = {
            "Content-Type": "application/json",
            "Authorization": `Bearer ${this.authToken}`,
        };
        const requestBody = {
            messages,
            model,
            stream: true,
            ...options
        };

        const response = await fetch(url, {
            method: "POST",
            headers,
            body: JSON.stringify(requestBody)
        });

        if (!response.ok) {
            throw new Error(`Request failed with status ${response.status}`);
        }

        const reader = response.body.getReader();
        const decoder = new TextDecoder("utf-8");

        while (true) {
            const { done, value } = await reader.read();
            if (done) break;

            const chunk = decoder.decode(value);
            const lines = chunk.split('\n').filter(line => line);

            for (const line of lines) {
                if (line === "data: [DONE]") break;
                if (line.startsWith("data: ")) {
                    const chunkContent = JSON.parse(line.substring("data: ".length))["choices"][0]["delta"];
                    if (chunkContent?.content) {
                        yield chunkContent.content;
                    }
                }
            }
        }
    }

    chatComplete(model, messages = [], options = {}) {
        // This is non-streaming method.
        const url = `${this.baseUrl}/v1.0/chat/completions`;
        const requestBody = {
            messages,
            model,
            stream: false,
            ...options
        };

        const xhr = new XMLHttpRequest();
        xhr.open("POST", url, false);
        xhr.setRequestHeader("Content-Type", "application/json");
        xhr.setRequestHeader("Authorization", `Bearer ${this.authToken}`);

        xhr.send(JSON.stringify(requestBody));

        if (xhr.status !== 200) {
            throw new Error(`Request failed with status ${xhr.status}`);
        }

        const response = JSON.parse(xhr.responseText);
        return response;
    }
    async _makeRequest(url, method, headers = {}, body = null, { onProgress, onSuccess, onError } = {}) {
        return new Promise((resolve, reject) => {
            const xhr = new XMLHttpRequest();
    
            xhr.open(method, url, true);
    
            // Set headers
            for (const [key, value] of Object.entries(headers)) {
                xhr.setRequestHeader(key, value);
            }
    
            // Track upload progress
            if (onProgress) {
                xhr.upload.onprogress = (event) => {
                    const total = event.lengthComputable ? event.total : body?.get('file')?.size;
                    if (total) {
                        onProgress({
                            loaded: event.loaded,
                            total: total,
                            percent: (event.loaded / total) * 100
                        });
                    }
                };
            }
    
            // Handle request success
            xhr.onload = () => {
                try {
                    const response = JSON.parse(xhr.responseText);
                    if (xhr.status >= 200 && xhr.status < 300) {
                        onSuccess && onSuccess(response);
                        resolve(response);
                    } else {
                        const errorDetails = response.result || 'Unknown error occurred';
                        const error = new Error(errorDetails);
                        onError && onError(error);
                        reject(error);
                    }
                } catch (err) {
                    const parseError = new Error('Failed to parse response: ' + err.message);
                    onError && onError(parseError);
                    reject(parseError);
                }
            };
    
            // Handle request error
            xhr.onerror = () => {
                const networkError = new Error('Network error occurred');
                onError && onError(networkError);
                reject(networkError);
            };
    
            // Send the request
            xhr.send(body);
        });
    }
}

class SkyscopeUser {
    constructor(name, email, password, group = "", detail = "", require_change_password = false) {
        this.user = {
            name,
            email,
            password,
            group,
            detail,
            require_change_password: require_change_password != false
        };
    }

    getUser() {
        return this.user;
    }
}

/*
Skyscope Chat complete example

-- Init --
const client = new SkyscopeClient("YOUR_API_TOKEN_HERE","http://localhost");

-- Streaming --
const messages = [{ role: "user", content: "hi" }];
(async () => {
    try {
        for await (const chunk of client.chatCompleteAsync("geminipro",messages)) {
            console.log(chunk);
        }
    } catch (error) {
        console.error(error.message);
    }
})();

-- Non-Streaming --
const messages = [{ role: "user", content: "hi" }];
const result = client.chatComplete("geminipro",messages);
console.log(result);
console.log(result.choices[0].message.content)

-- Create Base Model --
client.createBaseModel('test2', 'test_code2')
    .then(response => console.log('Base Model Created:', response))
    .catch(error => console.error('Error:', error));

-- List Base Models --
client.listBaseModels()
    .then(response => console.log(response))
    .catch(error => console.error('Error:', error));

-- List Bots --
client.listBots()
    .then(response => console.log(response))
    .catch(error => console.error('Error:', error));

-- Create Room --
client.createRoom([1,2,3])
    .then(response => console.log(response))
    .catch(error => console.error('Error:', error));

-- Delete Room --
client.deleteRoom(1)
    .then(response => console.log(response))
    .catch(error => console.error('Error:', error));

-- Read Room list --
client.listRooms()
    .then(response => console.log(response))
    .catch(error => console.error('Error:', error));

-- Simple interface for uploading file --
document.documentElement.innerHTML = '';
const fileInput = document.createElement('input');
fileInput.type = 'file';
fileInput.id = 'test';
const uploadButton = document.createElement('button');
uploadButton.textContent = 'Upload File';
document.body.appendChild(fileInput);
document.body.appendChild(uploadButton);
uploadButton.addEventListener('click', () => {
    const file = fileInput.files[0];
    if (file) {
        client.uploadFile(file)
        .then((response) => {
            const result = document.createElement('p')
            result.textContent = JSON.stringify(response)
            document.body.appendChild(result)
            console.log(response)
            })
        .catch(error => console.error('Error:', error));
    } else {
        alert('Please select a file.');
    }
});

-- Create user --
client.createUsers([new SkyscopeUser('User','User@gmail.com','Debug')])
    .then(response => console.log(response))
    .catch(error => console.error('Error:', error));
-- List cloud --
client.listCloud()
    .then(response => console.log(response))
    .catch(error => console.error('Error:', error));
-- Delete cloud file --
client.deleteCloud('hi.txt')
    .then(response => console.log(response))
    .catch(error => console.error('Error:', error));
*/
