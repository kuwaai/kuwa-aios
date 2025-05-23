from datetime import date
import os
import sys
import random

# =============================================================================
def translateHint(userInput: str, translation: str) -> str:
    prompt = f"""
    你的任務: 我會給你兩段文本，一段是中文，一段是英文，你需要給我中文單詞與英文單詞之間的對應。像是你在中文文本看到"蘋果"，你需要告訴我"蘋果"對應到英文的"apple"。先列出中文文本中的重要單詞，再從英文文本中找出對應的英文單詞，並確保對應正確無誤。
    ---
    中文文本:
    {userInput}
    ---
    英文文本:
    {translation}
    ---
    """
    return prompt
def chiToEng(userInput: str) -> str:
    prompt = f"""
    Task: Translate the provided Chinese text into fluent and accurate English. Ensure that the translation captures the nuances and tone of the original text while making it clear and easy to understand for an English-speaking audience. Pay attention to cultural references, idiomatic expressions, and specific terms, providing context where necessary to maintain the original meaning.
    ---
    Text to Translate:
    {userInput}
    ---
    Please provide the English translation of the text. Ensure that the translation is accurate, natural-sounding, and captures the essence of the original content. Avoid literal translations and strive for idiomatic and culturally appropriate language.
    """
    return prompt

# =============================================================================
def engToChi(engText: str, addiPrompt: str) -> str:
    if len(addiPrompt) == 0: addiPrompt = "無特別要求"
    prompt = f"""
    你的角色: 一名台灣在地的翻譯員，身為一位台灣人，你會將翻譯變得更具有台灣的特色，並且保留原文的意思。
    你的任務: 將以下的內容翻譯成台灣人使用的中文，並且保留原本的格式、符號、內容。請全部使用繁體中文書寫。
    特別要求: {addiPrompt}
    ---
    英文內容:
    {engText}
    ---
    """

    engPrompt = f"""
    Task: Translate the provided English text into fluent and accurate Chinese. Ensure that the translation captures the nuances and tone of the original text while making it clear and easy to understand for a Chinese-speaking audience. Pay attention to cultural references, idiomatic expressions, and specific terms, providing context where necessary to maintain the original meaning.
    ---
    Text to Translate:
    {engText}
    ---
    Please complete whole translation of the text without missing any content.
    """
    return prompt
# =============================================================================
def expand(userInput: str, translationHint: str) -> str:
    prompt = f"""
    Role: You are a writer with a flair for eloquence and sophistication. Your task is to transform the given input into a more complete, richly detailed, and vivid narrative. Your writing should flow seamlessly, offering a depth of content that enhances the original message. Where specific terms are used, provide clear explanations. When describing events or activities, outline the process, highlight important details, specify timelines, and include any relevant precautions. The goal is to convey your literary talent and deep knowledge, ensuring that readers not only understand the content but also appreciate the elegance and thoroughness of your writing.

    Task: Rewrite the "User Input" provided into a fuller, more elaborate text, possibly breaking it into multiple paragraphs. The more complete and enriched the narrative, the better. Additionally, consider the "Translation Hint" provided to ensure that the expanded text accurately reflects the original content while adding depth and detail.
    ---
    Here is the User Input:
    {userInput}
    ---
    Translation Hint:
    {translationHint}
    ---
    """
    return prompt

# =============================================================================
def topic(expandOut: str) -> str:
    prompt = f"""
    Role: As an experienced editor, read the following article and distill its main idea into one or two sentences.
    Task: Please read the following article and summarize its main idea or central theme in one or two sentences.
    ---
    Article Content:
    {expandOut}
    ---
    After reading the article, provide a concise summary of its main idea in a formal tone.
    """
    return prompt
# =============================================================================
def info(expandOut: str) -> str:
    prompt = f"""
    Role: You are a skilled writer tasked with creating a formal document based on the provided content. Your role is to craft a professional and structured explanation that conveys the key points of the original text in a clear and concise manner. Your writing should be precise, organized, and adhere to the formal conventions of official documents.
    Task: Based on the following content, create a formal explanation using the provided format. Your explanation should be structured, informative, and reflect the key points of the original text.
    ---
    Content:
    {expandOut}
    ---
    Output Format:
    Explanation: (Remember to include line breaks)
    一、(Your output)
    二、(Your output)
    三、(Your output)
    四、(Your output)
    If necessary, you may include a fifth paragraph or more.
    """
    return prompt
# =============================================================================
# default model: TAIDE
def officialize(topic: str, info: str) -> str:
    def getFiles():
        curPath = os.path.dirname(os.path.abspath(__file__))
        files = os.listdir(curPath+"/gondoc_gen")
        choseFile = list()
        for _ in range(3):
            random_file = random.choice(files)
            choseFile.append(os.path.join(curPath+"/gondoc_gen", random_file))
        return choseFile
    
    def getFewShot():
        shots = list()
        files = getFiles()
        for file in files:
            with open(file, "r", encoding="utf-8") as f:
                shots.append(f.read())
        return shots
    
    shots = getFewShot()
    prompt = f"""
    角色設定: 你是一位「公文用語專家」，專門負責將接收到的「文本」依照正式公文的要求進行改寫。
    任務內容: 請根據文本，將其轉換為正式的公文用語。請注意格式、用詞、結構和內容的正確性，確保公文的專業性和嚴謹性。生成的公文必須包含以下要素: 主旨、說明，若需要擬辦事項、附註等，請依照文本內容的需要進行添加。請確保公文的格式正確，用詞專業，內容完整。除了格式以外，最重要的是用字遣詞，語氣上必須使用正式、專業的公文用語。請參考以下的範例的「格式」、「語氣」、「用字」進行改寫。
    ---
    範例一:
    {shots[0]}
    ---
    範例二:
    {shots[1]}
    ---
    範例三:
    {shots[2]}
    ---
    請將下面的內容轉換為正式的台灣公文用語:
    {topic}\n{info}
    ---
    """
    return prompt
