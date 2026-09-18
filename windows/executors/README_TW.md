## Windows可攜式模型架設教學
目前為了將模型架設簡單化，在Windows可攜版準備了一套簡易的模型管理系統，該管理系統僅為簡易使用與測試，並不建議用於Production的情況，如果是希望用於Production，請瀏覽該[教學](../../src/executor/README_TW.md)。

這邊的模型架設教學假設您已經執行過`windows/build & start.bat`或`windows/build.bat`+`windows/start.bat`，且系統可登入沒有問題，如果以上不符合您的情況，請先回到[該篇教學](../README_TW.md)的步驟。

## 介紹
每個 executor 都位於 `windows/executors` 下的獨立資料夾，您可以任意更改資料夾名稱。

executor 以 YAML 範本搭配 JavaScript 啟動模組來管理：

- `_run.yaml` — 該 executor 的預設啟動設定（已納入版控的範本），指向一個 `run.js` 檔案。
- `run.yaml` — 每台機器、可由使用者編輯的設定，已被 gitignore。若不存在，啟動器會在啟動時自動將 `_run.yaml` 複製為 `run.yaml`。
- `run.js` — 該 executor 已納入版控的啟動邏輯。啟動器會在專屬的 worker thread（每個 executor 一個緒）中執行它，取代舊的批次指令檔。它負責設定模型並啟動 executor 行程。
- `windows/_config.yaml` — 預設要執行的 executor 資料夾清單（已納入版控的範本）。
- `windows/config.yaml` — 您本機的選擇，已被 gitignore。若不存在，啟動器會在啟動時自動將 `_config.yaml` 複製為 `config.yaml`。

要選擇要執行哪些 executor，請編輯 `config.yaml`，只保留您要的資料夾名稱。它就是一個 YAML 字串陣列：

```yaml
# windows/config.yaml
- chatgpt
- ollama
```

每個 executor 的 `run.yaml` 只負責選定 access code 與啟動模組：

```yaml
# windows/executors/<executor>/run.yaml
version: 1
access_code: '.model:openai/gpt'
run: run.js
```

實際的啟動邏輯位於 `run.js`，它匯出一個 `async function run(api)`。`api` 物件提供了諸如 `modelConfig(...)`、`startExecutor(...)`、`startPython(...)`、`importBot(...)`、`run(...)`、`sh(...)`、`spawnBg(...)` 等輔助函式。例如：

```js
// windows/executors/chatgpt/run.js
module.exports = async function run(api) {
  await api.modelConfig('.model:openai/gpt', 'ChatGPT', { image: 'chatgpt.png', order: 401100 });
  api.startExecutor(['chatgpt', '--access_code', '.model:openai/gpt']);
};
```

要自訂某個 executor，請編輯它的 `run.js`（模型參數、API 金鑰、模型路徑等）。啟動器會在它管理的專屬 worker thread 中執行每個 `run.js`。若要將 `run.yaml` 還原為預設值，刪除它，啟動器會自 `_run.yaml` 重新建立。設定變更後需重新啟動才會生效。

也可以將圖片放置於該資料夾內，讓其在初次建立模型的時候自動放入圖片(如果網站上已創建該模型，則需手動放入圖片)

預設這些模型都只會開放給擁有管理Tab權限的使用者。

## 模型快速設定教學

以下每個 executor 的流程都相同：編輯該 executor 的 `run.js` 設定您的參數，接著重新啟動。kuwa-executor 指令參數請參照[該份教學](../../src/executor/README_TW.md)。

### ChatGPT
1. 進入`chatgpt`資料夾。
2. 開啟 `run.js`，在 `startExecutor([...])` 的參數陣列中加入您的 OpenAI API Token 或額外參數（例如 `'--api_key', '<token>'`）。若維持原樣則使用各 bot 自帶的 Token。

### Gemini
1. 進入`geminipro`資料夾。
2. 開啟 `run.js`，在 `startExecutor([...])` 的參數陣列中加入您的 Google API Token 或額外參數。

### LLaMA.cpp
1. 進入`llamacpp`資料夾。
2. 放置 `.gguf` 檔案在該資料夾下。
3. 開啟 `run.js`，將 `--model_path` 參數設為該 `.gguf` 檔名（以及任何額外參數）。

### Huggingface
1. 進入`huggingface`資料夾。
2. 放置模型與 tokenizer。
3. 開啟 `run.js`，將 `--model_path` 參數設為本機路徑或 HuggingFace repo id。

### Custom
- 此處為預留的自訂模型，使用者可以自行改寫一版 executor(繼承 kuwa LLMWorker)。開啟 `run.js`，將 `spawnBg('python', [...])` 呼叫指向您的 `.py` 檔案。

## 進階用法
你可以使用相同的 `access_code` 在多個資料夾中執行多個模型。這樣做可以同時處理多個請求。你也可以複製資料夾以建立更多的模型執行。這些模型不一定要在同一台主機上，你可以將它們分散在多台主機上，只需要將 Kernel endpoint 設定到 kernel 上即可，詳細的教學請見[此處](../../src/executor/README_TW.md)。

如果你正在使用 ollama 或 vLLM 等其他 TGI 框架，你也可以使用 ChatGPT 的 worker 快速串接。