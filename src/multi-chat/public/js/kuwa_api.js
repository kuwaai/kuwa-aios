/**
 * KuwaClient provides a JavaScript interface for interacting with the Kuwa API.
 * It handles request authentication, endpoint routing, and data serialization
 * for various API operations like managing models, bots, files, and chat rooms.
 */
class KuwaClient {
    /**
     * Creates an instance of the KuwaClient.
     * @param {string} authToken - The Bearer token required for API authentication. This token
     * must be provided to authorize requests.
     * @param {string} [baseUrl="http://localhost"] - The base URL of the Kuwa API server.
     * Defaults to "http://localhost" for local development.
     */
    constructor(authToken, baseUrl = "http://localhost") {
        if (!authToken) {
            throw new Error("Authentication token is required.");
        }
        this.authToken = authToken;
        this.baseUrl = baseUrl;
    }

    /**
     * The primary method for making modern, asynchronous HTTP requests using the Fetch API.
     * This is used for all API calls that do not require upload progress tracking.
     * It is configured to *never* send cookies, ensuring stateless requests.
     * @private
     * @param {string} url - The complete URL for the API endpoint.
     * @param {string} method - The HTTP method (e.g., "GET", "POST", "DELETE").
     * @param {object} [headers={}] - A map of request headers.
     * @param {string | FormData | null} [body=null] - The body of the request.
     * @param {object} [callbacks={}] - Optional callbacks for success and error events.
     * @param {function(object): void} [callbacks.onSuccess] - Called with the response data on success.
     * @param {function(Error): void} [callbacks.onError] - Called with an Error object on failure.
     * @returns {Promise<object>} A promise that resolves with the JSON response from the API.
     * @throws {Error} Throws an error if the network request fails or the API returns an error status.
     */
    async _makeFetchRequest(url, method, headers = {}, body = null, { onSuccess, onError } = {}) {
        try {
            const response = await fetch(url, {
                method: method,
                headers: headers,
                body: body,
                credentials: 'omit' // This is critical: prevents the browser from sending cookies.
            });

            const responseData = await response.json();

            if (!response.ok) {
                // Extract a meaningful error message from the API response, or use the HTTP status.
                const errorDetails = responseData.result || `HTTP error! Status: ${response.status}`;
                const error = new Error(errorDetails);
                onError?.(error);
                throw error; // Reject the promise for the calling function.
            }

            onSuccess?.(responseData);
            return responseData;

        } catch (err) {
            // This block catches fetch network errors, JSON parsing errors, or the re-thrown error above.
            const error = new Error('Request failed: ' + err.message);
            onError?.(error);
            throw error;
        }
    }

    /**
     * A specialized method for making requests using XMLHttpRequest (XHR).
     * This method is used *only* for file uploads because the Fetch API does not
     * natively support upload progress events.
     * @private
     * @param {string} url - The complete URL for the API endpoint.
     * @param {string} method - The HTTP method, typically "POST".
     * @param {object} [headers={}] - A map of request headers.
     * @param {FormData} body - The request body, expected to be FormData for file uploads.
     * @param {object} [callbacks={}] - Optional callbacks, including `onProgress`.
     * @param {function({loaded: number, total: number, percent: number}): void} [callbacks.onProgress] - Called periodically with upload progress.
     * @param {function(object): void} [callbacks.onSuccess] - Called with the response data on success.
     * @param {function(Error): void} [callbacks.onError] - Called with an Error object on failure.
     * @returns {Promise<object>} A promise that resolves with the JSON response from the API.
     */
    async _makeXHRRequest(url, method, headers = {}, body = null, { onProgress, onSuccess, onError } = {}) {
        return new Promise((resolve, reject) => {
            const xhr = new XMLHttpRequest();
            xhr.open(method, url, true);

            for (const [key, value] of Object.entries(headers)) {
                xhr.setRequestHeader(key, value);
            }

            // Set up the progress event listener for the upload.
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

            xhr.onload = () => {
                try {
                    const response = JSON.parse(xhr.responseText);
                    if (xhr.status >= 200 && xhr.status < 300) {
                        onSuccess?.(response);
                        resolve(response);
                    } else {
                        const errorDetails = response.result || 'Unknown error occurred';
                        const error = new Error(errorDetails);
                        onError?.(error);
                        reject(error);
                    }
                } catch (err) {
                    const parseError = new Error('Failed to parse response: ' + err.message);
                    onError?.(parseError);
                    reject(parseError);
                }
            };

            xhr.onerror = () => {
                const networkError = new Error('Network error occurred');
                onError?.(networkError);
                reject(networkError);
            };

            xhr.send(body);
        });
    }


    /**
     * Registers a new base model in the system.
     * @param {string} name - The desired name for the new base model.
     * @param {string} accessCode - A unique access code for the model.
     * @param {object} [options={}] - Additional key-value pairs for model creation.
     * @param {object} [callbacks={}] - Optional callbacks for success and error events.
     * @returns {Promise<object>} A promise resolving with the API confirmation.
     * @throws {Error} Throws if the API request fails.
     */
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
        return this._makeFetchRequest(url, "POST", headers, JSON.stringify(requestBody), callbacks);
    }

    /**
     * Fetches a list of all base models available to the user.
     * @param {object} [callbacks={}] - Optional callbacks for success and error events.
     * @returns {Promise<object>} A promise resolving with the list of base models.
     * @throws {Error} Throws if the API request fails.
     */
    async listBaseModels(callbacks = {}) {
        const url = `${this.baseUrl}/api/user/read/models`;
        const headers = {
            "Content-Type": "application/json",
            "Authorization": `Bearer ${this.authToken}`,
        };
        return this._makeFetchRequest(url, "GET", headers, null, callbacks);
    }

    /**
     * Fetches a list of all bots available to the user.
     * @param {object} [callbacks={}] - Optional callbacks for success and error events.
     * @returns {Promise<object>} A promise resolving with the list of bots.
     * @throws {Error} Throws if the API request fails.
     */
    async listBots(callbacks = {}) {
        const url = `${this.baseUrl}/api/user/read/bots`;
        const headers = {
            "Content-Type": "application/json",
            "Authorization": `Bearer ${this.authToken}`,
        };
        return this._makeFetchRequest(url, "GET", headers, null, callbacks);
    }

    /**
     * Fetches a list of all knowledge bases created by the user.
     * @param {object} [callbacks={}] - Optional callbacks for success and error events.
     * @returns {Promise<object>} A promise resolving with the list of knowledge bases.
     * @throws {Error} Throws if the API request fails.
     */
    async listKnowledges(callbacks = {}) {
        const url = `${this.baseUrl}/api/user/read/knowledges`;
        const headers = {
            "Content-Type": "application/json",
            "Authorization": `Bearer ${this.authToken}`,
        };
        return this._makeFetchRequest(url, "GET", headers, null, callbacks);
    }

    /**
     * Fetches the contents (list of files) of a specific knowledge base.
     * @param {string} name - The name of the knowledge base to inspect.
     * @param {object} [callbacks={}] - Optional callbacks for success and error events.
     * @returns {Promise<object>} A promise resolving with the list of files in the knowledge base.
     * @throws {Error} Throws if the API request fails.
     */
    async listKnowledgeFiles(name, callbacks = {}) {
        const url = `${this.baseUrl}/api/user/read/knowledges/${name}`;
        const headers = {
            "Content-Type": "application/json",
            "Authorization": `Bearer ${this.authToken}`,
        };
        return this._makeFetchRequest(url, "GET", headers, null, callbacks);
    }

    /**
     * Fetches a list of all chat rooms the user has access to.
     * @param {object} [callbacks={}] - Optional callbacks for success and error events.
     * @returns {Promise<object>} A promise resolving with the list of chat rooms.
     * @throws {Error} Throws if the API request fails.
     */
    async listRooms(callbacks = {}) {
        const url = `${this.baseUrl}/api/user/read/rooms`;
        const headers = {
            "Content-Type": "application/json",
            "Authorization": `Bearer ${this.authToken}`,
        };
        return this._makeFetchRequest(url, "GET", headers, null, callbacks);
    }

    /**
     * Fetches the contents of a directory in the user's cloud storage.
     * @param {string} [path=''] - The path to the directory. Use an empty string for the root directory.
     * @param {object} [callbacks={}] - Optional callbacks for success and error events.
     * @returns {Promise<object>} A promise resolving with the list of files and folders at the given path.
     * @throws {Error} Throws if the API request fails.
     */
    async listCloud(path = '', callbacks = {}) {
        const url = `${this.baseUrl}/api/user/read/cloud${path}`;
        const headers = {
            "Content-Type": "application/json",
            "Authorization": `Bearer ${this.authToken}`,
        };
        return this._makeFetchRequest(url, "GET", headers, null, callbacks);
    }

    /**
     * Creates one or more new users in the system.
     * @param {KuwaUser[]} users - An array of `KuwaUser` instances, each representing a new user.
     * @param {object} [callbacks={}] - Optional callbacks for success and error events.
     * @returns {Promise<object>} A promise resolving with the API confirmation.
     * @throws {Error} Throws if the API request fails.
     */
    async createUsers(users, callbacks = {}) {
        const url = `${this.baseUrl}/api/user/create/user`;
        const headers = {
            "Content-Type": "application/json",
            "Authorization": `Bearer ${this.authToken}`,
        };
        const requestBody = {
            users: users.map(userInstance => userInstance.getUser())
        };
        return this._makeFetchRequest(url, "POST", headers, JSON.stringify(requestBody), callbacks);
    }

    /**
     * Creates a new chat room and associates it with the specified bots.
     * @param {number[]} bot_ids - An array of bot IDs to include in the new room.
     * @param {object} [callbacks={}] - Optional callbacks for success and error events.
     * @returns {Promise<object>} A promise resolving with the details of the newly created room.
     * @throws {Error} Throws if the API request fails.
     */
    async createRoom(bot_ids, callbacks = {}) {
        const url = `${this.baseUrl}/api/user/create/room`;
        const headers = {
            "Content-Type": "application/json",
            "Authorization": `Bearer ${this.authToken}`,
        };
        const requestBody = {
            llm: bot_ids
        };
        return this._makeFetchRequest(url, "POST", headers, JSON.stringify(requestBody), callbacks);
    }
    /**
     * Uploads a file to the user's cloud storage with progress tracking.
     * This method uniquely uses XHR to provide progress events.
     * @param {File} file - The file object, typically from an `<input type="file">` element.
     * @param {object} [callbacks={}] - Optional callbacks, including `onProgress` for upload status.
     * @param {function({loaded: number, total: number, percent: number}): void} [callbacks.onProgress] - Called periodically with upload progress.
     * @returns {Promise<object>} A promise resolving with the API response upon successful upload.
     * @throws {Error} Throws if the upload fails.
     */
    async uploadFile(file, callbacks = {}) {
        const url = `${this.baseUrl}/api/user/upload/file`;
        const headers = {
            "Authorization": `Bearer ${this.authToken}`,
        };
        const formData = new FormData();
        formData.append('file', file);

        if (file.webkitRelativePath) {
            formData.append('webkitRelativePath', file.webkitRelativePath);
        }

        // Use the dedicated XHR request method to get progress tracking.
        return this._makeXHRRequest(url, "POST", headers, formData, callbacks);
    }
    
    /**
     * Permanently deletes a knowledge base and all of its contents.
     * @param {string} name - The name of the knowledge base to delete.
     * @param {object} [callbacks={}] - Optional callbacks for success and error events.
     * @returns {Promise<object>} A promise resolving with the API confirmation message.
     * @throws {Error} Throws if the API request fails.
     */
    async deleteKnowledgeBase(name, callbacks = {}) {
        const url = `${this.baseUrl}/api/user/delete/knowledges/${name}`;
        const headers = {
            "Content-Type": "application/json",
            "Authorization": `Bearer ${this.authToken}`,
        };
        return this._makeFetchRequest(url, "DELETE", headers, null, callbacks);
    }

    /**
     * Permanently deletes a single file from a specified knowledge base.
     * @param {string} name - The name of the knowledge base containing the file.
     * @param {string} file - The name of the file to delete.
     * @param {object} [callbacks={}] - Optional callbacks for success and error events.
     * @returns {Promise<object>} A promise resolving with the API confirmation message.
     * @throws {Error} Throws if the API request fails.
     */
    async deleteKnowledgeBaseFile(name, file, callbacks = {}) {
        const url = `${this.baseUrl}/api/user/delete/knowledges/${name}/files/${file}`;
        const headers = {
            "Content-Type": "application/json",
            "Authorization": `Bearer ${this.authToken}`,
        };
        return this._makeFetchRequest(url, "DELETE", headers, null, callbacks);
    }

    /**
     * Permanently deletes a chat room.
     * @param {number} room_id - The unique identifier of the room to delete.
     * @param {object} [callbacks={}] - Optional callbacks for success and error events.
     * @returns {Promise<object>} A promise resolving with the API confirmation message.
     * @throws {Error} Throws if the API request fails.
     */
    async deleteRoom(room_id, callbacks = {}) {
        const url = `${this.baseUrl}/api/user/delete/room/`;
        const headers = {
            "Content-Type": "application/json",
            "Authorization": `Bearer ${this.authToken}`,
        };
        const requestBody = {
            id: room_id
        };
        return this._makeFetchRequest(url, "DELETE", headers, JSON.stringify(requestBody), callbacks);
    }

    /**
     * Permanently deletes a single message from a chat room.
     * @param {number} message_id - The unique identifier of the message to delete.
     * @param {object} [callbacks={}] - Optional callbacks for success and error events.
     * @returns {Promise<object>} A promise resolving with the API confirmation message.
     * @throws {Error} Throws if the API request fails.
     */
    async deleteMessage(message_id, callbacks = {}) {
        const url = `${this.baseUrl}/api/user/delete/room/message`;
        const headers = {
            "Content-Type": "application/json",
            "Authorization": `Bearer ${this.authToken}`,
        };
        const requestBody = {
            id: message_id
        };
        return this._makeFetchRequest(url, "DELETE", headers, JSON.stringify(requestBody), callbacks);
    }

    /**
     * Edits the content of an existing message in a chat room.
     * @param {number} message_id - The unique identifier of the message to edit.
     * @param {string} new_message - The new text content for the message.
     * @param {object} [callbacks={}] - Optional callbacks for success and error events.
     * @returns {Promise<object>} A promise resolving with the API confirmation.
     * @throws {Error} Throws if the API request fails.
     */
    async editMessage(message_id, new_message, callbacks = {}) {
        const url = `${this.baseUrl}/api/user/update/room/message`;
        const headers = {
            "Content-Type": "application/json",
            "Authorization": `Bearer ${this.authToken}`,
        };
        const requestBody = {
            id: message_id,
            new_msg: new_message
        };
        return this._makeFetchRequest(url, "POST", headers, JSON.stringify(requestBody), callbacks);
    }
    
    /**
     * Renames an existing knowledge base.
     * @param {string} oldName - The current name of the knowledge base.
     * @param {string} newName - The desired new name for the knowledge base.
     * @param {object} [callbacks={}] - Optional callbacks for success and error events.
     * @returns {Promise<object>} A promise resolving with the API confirmation.
     * @throws {Error} Throws if the API request fails.
     */
    async renameKnowledgeBase(oldName, newName, callbacks = {}) {
        const url = `${this.baseUrl}/api/user/update/knowledges/${oldName}`;
        const headers = {
            "Content-Type": "application/json",
            "Authorization": `Bearer ${this.authToken}`,
        };
        const requestBody = {
            new_name: newName
        };
        return this._makeFetchRequest(url, "PATCH", headers, JSON.stringify(requestBody), callbacks);
    }

    /**
     * Renames a specific file within a knowledge base.
     * @param {string} knowledgeBaseName - The name of the knowledge base containing the file.
     * @param {string} oldFileName - The current name of the file.
     * @param {string} newFileName - The desired new name for the file.
     * @param {object} [callbacks={}] - Optional callbacks for success and error events.
     * @returns {Promise<object>} A promise resolving with the API confirmation.
     * @throws {Error} Throws if the API request fails.
     */
    async renameKnowledgeBaseFile(knowledgeBaseName, oldFileName, newFileName, callbacks = {}) {
        const url = `${this.baseUrl}/api/user/update/knowledges/${knowledgeBaseName}/files/${oldFileName}`;
        const headers = {
            "Content-Type": "application/json",
            "Authorization": `Bearer ${this.authToken}`,
        };
        const requestBody = {
            new_name: newFileName
        };
        return this._makeFetchRequest(url, "PATCH", headers, JSON.stringify(requestBody), callbacks);
    }

    /**
     * Deletes a file or an entire directory from the user's cloud storage.
     * @param {string} [path=''] - The full path to the file or directory to delete (e.g., '/folder/file.txt').
     * @param {object} [callbacks={}] - Optional callbacks for success and error events.
     * @returns {Promise<object>} A promise resolving with the API confirmation.
     * @throws {Error} Throws if the API request fails.
     */
    async deleteCloud(path = '', callbacks = {}) {
        const url = `${this.baseUrl}/api/user/delete/cloud${path}`;
        const headers = {
            "Content-Type": "application/json",
            "Authorization": `Bearer ${this.authToken}`,
        };
        return this._makeFetchRequest(url, "DELETE", headers, null, callbacks);
    }

    /**
     * Creates a new bot.
     * @param {string} llmAccessCode - The access code for the underlying LLM the bot will use.
     * @param {string} botName - The desired name for the new bot.
     * @param {object} [options={}] - Additional options, such as `visibility`.
     * @param {object} [callbacks={}] - Optional callbacks for success and error events.
     * @returns {Promise<object>} A promise resolving with the details of the newly created bot.
     * @throws {Error} Throws if the API request fails.
     */
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
        return this._makeFetchRequest(url, "POST", headers, JSON.stringify(requestBody), callbacks);
    }

    /**
     * Initiates a streaming chat completion, receiving the response in chunks.
     * This is an async generator function, designed to be used with a `for await...of` loop.
     * @param {string} model - The identifier for the language model to use (e.g., "geminipro").
     * @param {Array<{role: string, content: string}>} [messages=[]] - The conversation history.
     * @param {object} [options={}] - Additional options to pass to the language model.
     * @yields {string} A chunk of text content from the language model's response.
     * @throws {Error} Throws if the initial request to the API fails.
     */
    async *chatCompleteAsync(model, messages = [], options = {}) {
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
            body: JSON.stringify(requestBody),
            credentials: 'omit' // Ensure no cookies are sent
        });

        if (!response.ok) {
            throw new Error(`Chat completion request failed with status ${response.status}`);
        }

        const reader = response.body.getReader();
        const decoder = new TextDecoder("utf-8");

        while (true) {
            const { done, value } = await reader.read();
            if (done) break;

            const chunk = decoder.decode(value);
            const lines = chunk.split('\n').filter(line => line.trim() !== '');

            for (const line of lines) {
                if (line === "data: [DONE]") break;
                if (line.startsWith("data: ")) {
                    try {
                        const chunkContent = JSON.parse(line.substring("data: ".length))["choices"][0]["delta"];
                        if (chunkContent?.content) {
                            yield chunkContent.content;
                        }
                    } catch (e) {
                        console.error("Failed to parse stream chunk:", line);
                    }
                }
            }
        }
    }

    /**
     * Initiates a non-streaming chat completion, waiting for the full response.
     * @param {string} model - The identifier for the language model to use (e.g., "geminipro").
     * @param {Array<{role: string, content: string}>} [messages=[]] - The conversation history.
     * @param {object} [options={}] - Additional options to pass to the language model.
     * @returns {Promise<object>} A promise that resolves with the complete API response object.
     * @throws {Error} Throws if the API request fails.
     */
    async chatComplete(model, messages = [], options = {}) {
        const url = `${this.baseUrl}/v1.0/chat/completions`;
        const headers = {
            "Content-Type": "application/json",
            "Authorization": `Bearer ${this.authToken}`,
        };
        const requestBody = {
            messages,
            model,
            stream: false,
            ...options
        };

        return this._makeFetchRequest(url, "POST", headers, JSON.stringify(requestBody));
    }

    async updateProject(rebuildOnly = false) {
        if (!this.authToken) {
            throw new Error("Client is not authenticated.");
        }

        const url = `${this.baseUrl}/api/system/updateProject`;
        const headers = {
            "Content-Type": "application/json",
            "Authorization": `Bearer ${this.authToken}`,
        };
        const response = await fetch(url, {
            method: "POST",
            headers,
            body: JSON.stringify({ rebuildOnly }),
        });
        const contentType = response.headers.get('Content-Type') || '';

        if (contentType.includes('text/event-stream')) {
            const tokenUrl = `${url}?token=${encodeURIComponent(this.authToken)}`;
            return new EventSource(tokenUrl);
        }

        const data = await response.json().catch(() => ({}));
        if (!response.ok) {
            throw new Error(data.error || data.message || 'Failed to trigger update');
        }
        if (data.logsUrl) return new EventSource(data.logsUrl);
        throw new Error(data.error || 'No update log stream URL returned');
    }
}

/**
 * A helper class to structure user data for the `createUsers` API call.
 * An instance of this class represents a single user to be created.
 */
class KuwaUser {
    /**
     * Creates a representation of a user.
     * @param {string} name - The user's full name.
     * @param {string} email - The user's email address (must be unique).
     * @param {string} password - The user's desired password.
     * @param {string} [group=""] - An optional group to assign the user to.
     * @param {string} [detail=""] - Optional additional details about the user.
     * @param {boolean} [require_change_password=false] - If true, the user must change their password on first login.
     */
    constructor(name, email, password, group = "", detail = "", require_change_password = false) {
        this.user = {
            name,
            email,
            password,
            group,
            detail,
            require_change_password: require_change_password
        };
    }

    /**
     * Retrieves the user data in the format required by the API.
     * @returns {object} The user data object.
     */
    getUser() {
        return this.user;
    }
}

/*
// --- KuwaClient Usage Examples ---

// Initialize KuwaClient
const client = new KuwaClient("YOUR_API_TOKEN_HERE", "http://localhost");

// --- Chat Completion (Streaming) ---
// Initiates a chat with a language model, receiving responses in chunks.
// Example: Chatting with "geminipro"
(async () => {
    try {
        const messages = [{ role: "user", content: "Tell me a short story." }];
        console.log("Streaming chat response:");
        for await (const chunk of client.chatCompleteAsync("geminipro", messages)) {
            process.stdout.write(chunk); // Use process.stdout.write in Node.js for cleaner output
        }
        console.log("\nStreaming complete.");
    } catch (error) {
        console.error("Streaming chat error:", error.message);
    }
})();


// --- Chat Completion (Non-Streaming) ---
// NOTE: This function is now ASYNCHRONOUS. You must use await or .then().
// Example: Asking "geminipro" a question
(async () => {
    const messages = [{ role: "user", content: "What is the capital of France?" }];
    try {
        const result = await client.chatComplete("geminipro", messages);
        console.log("\nNon-streaming chat response:");
        console.log(result.choices[0].message.content);
    } catch (error) {
        console.error("Non-streaming chat error:", error.message);
    }
})();


// --- Create Base Model ---
// Creates a new base model with a specified name and access code.
// Example: Creating 'my_new_model'
client.createBaseModel('my_new_model', 'model_access_code_123')
    .then(response => console.log('Base Model Created:', response))
    .catch(error => console.error('Error creating base model:', error));


// --- List Base Models ---
// Retrieves a list of all existing base models.
client.listBaseModels()
    .then(response => console.log('Base Models:', response))
    .catch(error => console.error('Error listing base models:', error));


// --- List Bots ---
// Retrieves a list of all available bots.
client.listBots()
    .then(response => console.log('Bots:', response))
    .catch(error => console.error('Error listing bots:', error));


// --- List Knowledges ---
// Retrieves a list of all knowledge bases.
client.listKnowledges()
    .then(response => console.log('Knowledges:', response))
    .catch(error => console.error('Error listing knowledges:', error));


// --- List Knowledge Files ---
// Retrieves files within a specific knowledge base.
// Example: Listing files in 'my-knowledge-base'
client.listKnowledgeFiles("my-knowledge-base")
    .then(response => console.log('Knowledge Base Files:', response))
    .catch(error => console.error('Error listing knowledge base files:', error));


// --- Create Room ---
// Creates a new chat room with specified bot IDs.
// Example: Creating a room with bots 1, 2, and 3
client.createRoom([1, 2, 3])
    .then(response => console.log('Room Created:', response))
    .catch(error => console.error('Error creating room:', error));


// --- Delete Room ---
// Deletes a chat room by its ID.
// Example: Deleting room with ID 1
client.deleteRoom(1)
    .then(response => console.log('Room Deleted:', response))
    .catch(error => console.error('Error deleting room:', error));


// --- Delete Knowledge Base ---
// Deletes an entire knowledge base by its name.
// Example: Deleting 'old-kb'
client.deleteKnowledgeBase("old-kb")
    .then(response => console.log('Knowledge Base Deleted:', response))
    .catch(error => console.error('Error deleting knowledge base:', error));


// --- Delete Knowledge Base File ---
// Deletes a specific file from a knowledge base.
// Example: Deleting 'document.pdf' from 'my-kb'
client.deleteKnowledgeBaseFile("my-kb", "document.pdf")
    .then(response => console.log('Knowledge Base File Deleted:', response))
    .catch(error => console.error('Error deleting knowledge base file:', error));


// --- List Rooms ---
// Retrieves a list of all existing chat rooms.
client.listRooms()
    .then(response => console.log('Rooms:', response))
    .catch(error => console.error('Error listing rooms:', error));


// --- Upload File ---
// Provides an example of how to upload a file using a file input element.
// This code snippet is for a browser environment.
// It creates a file input and a button to trigger the upload.
if (typeof window !== 'undefined' && typeof document !== 'undefined') {
    document.documentElement.innerHTML = ''; // Clear existing HTML for the example
    const fileInput = document.createElement('input');
    fileInput.type = 'file';
    fileInput.id = 'uploadFileInput';
    const uploadButton = document.createElement('button');
    uploadButton.textContent = 'Upload File';
    document.body.appendChild(fileInput);
    document.body.appendChild(uploadButton);

    uploadButton.addEventListener('click', () => {
        const file = fileInput.files[0];
        if (file) {
            client.uploadFile(file, {
                onProgress: (progress) => {
                    console.log(`Upload progress: ${progress.percent.toFixed(2)}%`);
                }
            })
            .then((response) => {
                const resultDisplay = document.createElement('p');
                resultDisplay.textContent = `Upload successful: ${JSON.stringify(response)}`;
                document.body.appendChild(resultDisplay);
                console.log('File Upload Response:', response);
            })
            .catch(error => console.error('Error uploading file:', error));
        } else {
            alert('Please select a file to upload.');
        }
    });
}


// --- Create User ---
// Creates one or more new users.
// Example: Creating a single user
client.createUsers([new KuwaUser('JohnDoe', 'john.doe@example.com', 'securePassword123')])
    .then(response => console.log('User Created:', response))
    .catch(error => console.error('Error creating user:', error));


// --- List Cloud Contents ---
// Retrieves a list of files and folders in the user's cloud storage.
// Example: Listing contents of the root cloud directory
client.listCloud()
    .then(response => console.log('Cloud Contents:', response))
    .catch(error => console.error('Error listing cloud contents:', error));


// --- Delete Cloud File ---
// Deletes a specific file from the user's cloud storage.
// Example: Deleting 'report.docx' from the cloud
client.deleteCloud('/documents/report.docx')
    .then(response => console.log('Cloud File Deleted:', response))
    .catch(error => console.error('Error deleting cloud file:', error));

// --- Create Bot ---
// Creates a new bot with a specified LLM access code and name.
// Example: Creating a bot named 'MyAssistantBot' using 'gemini-llm-code'
client.createBot('gemini-llm-code', 'MyAssistantBot', { visibility: 1 }) // Visibility 1 for public
    .then(response => console.log('Bot Created:', response))
    .catch(error => console.error('Error creating bot:', error));

// --- Edit Message ---
// Edits an existing message in a chat room.
// Example: Editing message with ID 101 to "New updated message content."
client.editMessage(101, "New updated message content.")
    .then(response => console.log('Message Edited:', response))
    .catch(error => console.error('Error editing message:', error));

// --- Delete Message ---
// Deletes a specific message from a chat room.
// Example: Deleting message with ID 102
client.deleteMessage(102)
    .then(response => console.log('Message Deleted:', response))
    .catch(error => console.error('Error deleting message:', error));
*/