# -*- coding: utf-8 -*-
import os
import sys
import logging


from kuwa.executor import LLMExecutor, Modelfile
from kuwa.client import KuwaClient
from kuwa.executor.modelfile import ParameterDict

sys.path.append(os.path.dirname(os.path.abspath(__file__)))
import taskPrompt



logger = logging.getLogger(__name__)

class GongwenExecutor(LLMExecutor):
    def __init__(self):
        super().__init__()

    def extend_arguments(self, parser):
        """
        Override this method to add custom command-line arguments.
        """
        parser.add_argument('--delay', type=float, default=0.02, help='Inter-token delay')
        generator_group = parser.add_argument_group('Generator Options')
        generator_group.add_argument('--api_key', default=None, help='The API authentication token of Kuwa multi-chat WebUI')
        generator_group.add_argument('--model', default=None, help='The model name (access code) on Kuwa multi-chat WebUI')

    def setup(self):
        self.stop = False
        logger.setLevel(logging.DEBUG)
        
        self._app_setup()
    def _app_setup(self, params:ParameterDict=ParameterDict()):
        general_params = params["_"]
        generator_params = params["generator_"]

        self.taide = KuwaClient(
                model = 'taide',
                auth_token = general_params.get("user_token", self.args.api_key)
            )

        self.llama3_1 = KuwaClient(
            model = 'llama3.1-8b-instruct',
            auth_token = general_params.get("user_token", self.args.api_key)
        )
        pass

    async def callForResponse(self, inputMsg, client):
        try:
            async for chunk in client.chat_complete(messages=inputMsg):
                yield chunk
                
        except Exception as e:
            logger.debug(f"\033[31mError in {client.model}\033[0m")
            logger.debug(e)
        logger.info(f"\033[92m{client.model} Finish\033[0m")

    async def taskMustWrite(self, userInput, llm): # default: llama3.1
        stageName = "# 摘錄"
        yield f"\n{stageName}\n"
        
        msg = taskPrompt.mustWrite(userInput)
        messages = [
            {"role": "user", "content": msg}
        ]
        self.mustWrite = ""
        async for response in self.callForResponse(messages, llm):
            self.mustWrite += response
            yield response

    async def taskTransHint(self, userInput, userEng, llm):# default: llama3.1
        stageName = "# 翻譯提示"
        yield f"\n{stageName}\n"
        
        msg = taskPrompt.translateHint(userInput=userInput, translation=userEng)
        messages = [
            {"role": "user", "content": msg}
        ]
        self.translationHint = ""
        async for response in self.callForResponse(messages, llm):
            self.translationHint += response
            yield response

    async def taskChiToEng(self, chiText, llm): # default: llama3.1
        stageName = "## 中翻英"
        yield f"\n{stageName}\n"
        msg = taskPrompt.chiToEng(chiText)
        messages = [
            {"role": "user", "content": msg}
        ]
        
        self.userEng = ""
        async for response in self.callForResponse(messages, llm):
            self.userEng += response
            yield response

    async def taskEngToChi(self, engText, addiPrompt, llm): # default: TAIDE
        stageName = "## 英翻中"
        yield f"\n{stageName}\n"
        msg = taskPrompt.engToChi(engText, addiPrompt=addiPrompt)
        messages = [
            {"role": "user", "content": msg}
        ]
        
        self.chiTranslation = ""
        async for response in self.callForResponse(messages, llm):
            self.chiTranslation += response
            yield response

    async def taskExpand(self, userInput, translationHint, llm): # default: llama3.1
        stageName = "# 改寫"
        yield f"\n{stageName}\n"
        msg = taskPrompt.expand(userInput, translationHint)
        messages = [
            {"role": "user", "content": msg}
        ]
        
        self.expandEng = ""
        async for response in self.callForResponse(messages, llm):
            self.expandEng += response
            yield response

        # Translate the expanded text to Chinese
        # self.expandChi = ""
        # async for response in self.taskEngToChi(self.expandEng):
        #     self.expandChi += response
        #     yield response

    async def taskTopic(self, expandText, translationHint, llm): # default: llama3.1
        stageName = "# 主旨產生"
        yield f"\n{stageName}\n"
        msg = taskPrompt.topic(expandText)
        messages = [
            {"role": "user", "content": msg}
        ]
        
        mainTopicEng = ""
        async for response in self.callForResponse(messages, llm):
            mainTopicEng += response
            yield response
        yield "\n\n"

        # Translate the main topic to Chinese
        addiPrompt = f"""你現在正在翻譯「主旨」內容，請排除其他無用資訊，專心翻譯「主旨」的部分並且也只輸出「主旨」即可，輸出格式為: 主旨: 中文主旨。
        翻譯時請參考以下的提示，確保翻譯的準確性、通順性，並且保留原文的意思。避免直譯，力求成語地道、文意通順且必須使用台灣用語。
        翻譯提示:
        ---
        {translationHint}
        ---"""
        self.mainTopic = ""
        async for response in self.taskEngToChi(mainTopicEng, addiPrompt=addiPrompt, llm=self.taide):
            self.mainTopic += response
            yield response

    async def taskInfo(self, expandText, translationHint, llm): # default: llama3.1
        stageName = "# 說明產生"
        yield f"\n{stageName}\n"
        msg = taskPrompt.info(expandText)
        messages = [
            {"role": "user", "content": msg}
        ]
        
        infoEng = ""
        async for response in self.callForResponse(messages, llm):
            infoEng += response
            yield response
        
        yield "\n\n"

        # Translate the info to Chinese
        addiPrompt = f"""翻譯時請參考以下的提示，確保翻譯的準確性、通順性，並且保留原文的意思。避免直譯，力求成語地道、文意通順且必須使用台灣用語。請完整地把「說明」翻譯成中文。
        翻譯提示:
        ---
        {translationHint}
        ---"""
        self.info = ""
        async for response in self.taskEngToChi(infoEng, addiPrompt=addiPrompt, llm=self.taide):
            self.info += response
            yield response
    
    async def taskOfficialize(self, info, mainTopic, llm): # default: TAIDE
        stageName = "# 公文用語轉換"
        yield f"\n{stageName}\n"
        msg = taskPrompt.officialize(info, mainTopic)
        messages = [
            {"role": "user", "content": msg}
        ]
        
        offiVer = ""
        generator = self.callForResponse(messages, llm)
        async for response in generator:
            offiVer += response
            yield response
        
    def isMostlyEng(self, text, threshold=0.8):
        checkChar = [char for char in text if 32 <= ord(char) <= 126]
        return (len(checkChar) / len(text)) > threshold
            
    async def llm_compute(self, history: list[dict], modelfile:Modelfile):
        try:
            self.stop = False
            self._app_setup(params=modelfile.parameters)

            logger.info('\033[92mGongwen Start!\033[0m')

            userInput = history[-1]['content'].strip()
            userId    = modelfile.parameters.get('_user_id', 'unknown')
            
            self.chse = -1
            if os.path.exists(f"chse_user{userId}.txt"):
                with open(f"chse_user{userId}.txt", "r") as f:
                    self.chse = f.read().strip()

            if "沒事" in userInput:
                yield "沒事就不要找我啦，討厭 >///<"
                return
            
            elif self.chse in [str(_) for _ in range(1, 5+1)]: # if choice in 1~5
                if userInput in [str(_) for _ in range(1, 5+1)]:
                    yield f"""你已經選擇過功能{self.chse}。
                    若要重新選擇，請先輸入<font color='gray'>'/n'</font>取消服務。""".replace("\t", "")
                    return
                self.chse = int(self.chse)
                with open(f"chse_user{userId}.txt", "w") as f:
                    f.write('fin')
            
            elif userInput not in [str(_) for _ in range(1, 5+1)]: # if choice not in 1~5
                yield """《公文產生--English Version》
                請輸入1-5選擇功能:
                1 - 執行所有步驟 *(改寫、產生主旨、產生說明、用語轉換)*
                2 - 改寫
                3 - 產生主旨
                4 - 產生說明
                5 - 公文用語轉換"""

                with open(f"chse_user{userId}.txt", "w") as f:
                    f.write('wrong input')
                return
            
            else:
                options = {
                    '1' : "請提供你的內容，我會幫你執行所有步驟。\n*(改寫、產生主旨、產生說明、用語轉換)*",
                    '2' : "請簡單描述你的想法，我會幫你「改寫」成完整的文章。",
                    '3' : "請提供一篇完整的文章，我會幫你產生「主旨」。",
                    '4' : "請提供一篇完整的文章，我會幫你產生「說明」。",
                    '5' : """請提供具有「主旨」以及「說明」的文章，我會幫你將其轉換為「公文用語」。\n*範例: 以便->俾*""",
                    '6' : "測試用"
                }
                yield options[userInput]
                yield "<font color='gray'>"
                yield "\n\n*...輸入\'/n\'取消這次服務...*"
                yield "</font>"
                with open(f"chse_user{userId}.txt", "w") as f:
                    f.write(userInput)
                return

            if userInput == "/n":
                yield "好的，已經取消服務。"
                return
            # ================== Main Process ================== #
            task_map = {  # 1: Whole Process, 2: expand content only, 3: mainTopic only, 4: info only, 5: officialize only
                1: [lambda: self.taskChiToEng(userInput, llm=self.llama3_1),
                    lambda: self.taskTransHint(userInput=userInput, userEng=self.userEng, llm=self.taide),
                    lambda: self.taskExpand(userInput=self.userEng,   translationHint=self.translationHint, llm=self.llama3_1),
                    lambda: self.taskTopic(expandText=self.expandEng, translationHint=self.translationHint, llm=self.llama3_1), 
                    lambda: self.taskInfo (expandText=self.expandEng, translationHint=self.translationHint, llm=self.llama3_1), 
                    lambda: self.taskOfficialize(info=self.info, mainTopic=self.mainTopic, llm=self.taide)], 
                2: [lambda: self.taskChiToEng(userInput, llm=self.llama3_1),
                    lambda: self.taskTransHint(userInput=userInput, userEng=self.userEng, llm=self.taide),
                    lambda: self.taskExpand(userInput=self.userEng,   translationHint=self.translationHint, llm=self.llama3_1)],                                                                               
                3: [lambda: self.taskTopic(expandText=self.expandEng, translationHint="No corresponding", llm=self.llama3_1)],                                                                                         
                4: [lambda: self.taskInfo (expandText=self.expandEng, translationHint="No corresponding", llm=self.llama3_1)],                                                                                            
                5: [lambda: self.taskOfficialize(info=self.info, mainTopic=self.mainTopic, llm=self.taide)],                                                          
            }

            # adjest the input for single task
            if self.chse == 3: # Main Topic only
                self.expandEng = ""
                if self.isMostlyEng(userInput):
                    self.expandEng = userInput
                else:
                    yield "正在將您的輸入轉換為英文"
                    async for response in self.taskChiToEng(userInput, llm=self.llama3_1):
                        self.expandEng += response
            elif self.chse == 4:# Info only
                self.expandEng = ""
                if self.isMostlyEng(userInput):
                    self.expandEng = userInput
                else:
                    yield "正在將您的輸入轉換為英文"
                    async for response in self.taskChiToEng(userInput, llm=self.llama3_1):
                        self.expandEng += response
            elif self.chse == 5: # Officialize only
                self.mainTopic = userInput
                self.info = ""
            elif self.chse == 6:
                self.expandEng = ""
                if self.isMostlyEng(userInput):
                    self.expandEng = userInput
                else:
                    yield "正在將您的輸入轉換為英文"
                    async for response in self.taskChiToEng(userInput, llm=self.llama3_1):
                        self.expandEng += response

            
            for task in task_map[self.chse]: # Task selection
                async for response in task(): # Iterate the tasks
                    if self.stop:
                        yield "<font color='red'>"
                        yield "\n\n# 您已經中斷生成\n\n"
                        yield "</font>"
                        return
                    
                    yield response

                # Separte each stage
                yield "\n\n"
                yield "---"
                yield "\n"
                
            
        except Exception as e:
            logger.exception("Error occurs during generation.")
            yield str(e)
        finally:
            logger.info('\033[92m [Gongwen DONE]\n\033[0m')
            logger.debug("finished")

    async def abort(self):
        self.stop = True
        logger.debug("aborted")
        return "Aborted"

if __name__ == "__main__":
    executor = GongwenExecutor()
    executor.run()
    