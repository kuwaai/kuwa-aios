#define MyAppName "Kuwa GenAI OS Models"
#define MyAppVersion "v0.4.0"
#define MyAppPublisher "Kuwa AI"
#define MyAppURL "https://kuwaai.tw/os/intro"
#define MyAppIcon "..\..\src\multi-chat\public\images\kuwa-logo.ico"

[Setup]
AppId={{B37EB0AF-B52C-4200-B80F-671FBCE385DC}
AppName={#MyAppName}
AppVersion={#MyAppVersion}
AppPublisher={#MyAppPublisher}
AppPublisherURL={#MyAppURL}
AppSupportURL={#MyAppURL}
AppUpdatesURL={#MyAppURL}
DefaultDirName=C:/kuwa/GenAI OS
DefaultGroupName=Kuwa GenAI OS
AllowNoIcons=yes
LicenseFile=../../LICENSE
PrivilegesRequired=lowest
OutputDir=.
OutputBaseFilename=Kuwa-AIOS-Model-Installer
SetupIconFile={#MyAppIcon}
Compression=lzma
SolidCompression=yes
WizardStyle=modern
CreateUninstallRegKey=no
Uninstallable=no

[Languages]
Name: "english"; MessagesFile: "compiler:Default.isl"
Name: "chinesetraditional"; MessagesFile: "compiler:Languages\ChineseTraditional.isl"
Name: "chinesesimplified"; MessagesFile: "compiler:Languages\ChineseSimplified.isl"
Name: "czech"; MessagesFile: "compiler:Languages\Czech.isl"
Name: "french"; MessagesFile: "compiler:Languages\French.isl"
Name: "german"; MessagesFile: "compiler:Languages\German.isl"
Name: "japanese"; MessagesFile: "compiler:Languages\Japanese.isl"
Name: "korean"; MessagesFile: "compiler:Languages\Korean.isl"

; Inno Setup supports several languages by default, but Kuwa currently lacks
; translations for these languages. Contributions are welcome.
; Name: "armenian"; MessagesFile: "compiler:Languages\Armenian.isl"
; Name: "brazilianportuguese"; MessagesFile: "compiler:Languages\BrazilianPortuguese.isl"
; Name: "bulgarian"; MessagesFile: "compiler:Languages\Bulgarian.isl"
; Name: "catalan"; MessagesFile: "compiler:Languages\Catalan.isl"
; Name: "corsican"; MessagesFile: "compiler:Languages\Corsican.isl"
; Name: "danish"; MessagesFile: "compiler:Languages\Danish.isl"
; Name: "dutch"; MessagesFile: "compiler:Languages\Dutch.isl"
; Name: "finnish"; MessagesFile: "compiler:Languages\Finnish.isl"
; Name: "hebrew"; MessagesFile: "compiler:Languages\Hebrew.isl"
; Name: "hungarian"; MessagesFile: "compiler:Languages\Hungarian.isl"
; Name: "icelandic"; MessagesFile: "compiler:Languages\Icelandic.isl"
; Name: "italian"; MessagesFile: "compiler:Languages\Italian.isl"
; Name: "norwegian"; MessagesFile: "compiler:Languages\Norwegian.isl"
; Name: "polish"; MessagesFile: "compiler:Languages\Polish.isl"
; Name: "portuguese"; MessagesFile: "compiler:Languages\Portuguese.isl"
; Name: "russian"; MessagesFile: "compiler:Languages\Russian.isl"
; Name: "slovak"; MessagesFile: "compiler:Languages\Slovak.isl"
; Name: "slovenian"; MessagesFile: "compiler:Languages\Slovenian.isl"
; Name: "spanish"; MessagesFile: "compiler:Languages\Spanish.isl"
; Name: "turkish"; MessagesFile: "compiler:Languages\Turkish.isl"
; Name: "ukrainian"; MessagesFile: "compiler:Languages\Ukrainian.isl"

[Components]
Name: "models"; Description: "Model Selection"; Types: full custom;Flags: fixed;
Name: "models\llama3_point_1_taide_lx_8_q4_km"; Description: "Llama3.1 TAIDE LX-8_Q4_KM"; Types: custom; ExtraDiskSpaceRequired:5261727040;
Name: "models\gemma_3_4b_it"; Description: "Gemma 3 4B"; Types: custom; ExtraDiskSpaceRequired:8639654085;
; Name: "models\phi4_multimodal_it"; Description: "Phi 4 Multimodal"; Types: custom; ExtraDiskSpaceRequired:11177094757;

[Files]
Source: "{tmp}\models\taide\Llama-3.1-TAIDE-LX-8B-Chat-Q4_K_M.gguf"; DestDir: "{app}\windows\executors\taide\"; Flags: external;Components: "models\llama3_point_1_taide_lx_8_q4_km"
Source: "{tmp}\models\gemma3-4b\*"; DestDir: "{app}\windows\executors\gemma3-4b\"; Flags: external;Components: "models\gemma_3_4b_it";
; Source: "{tmp}\models\phi4\*"; DestDir: "{app}\windows\executors\phi4\"; Flags: external;Components: "models\phi4_multimodal_it";
Source: "..\..\windows\executors\taide\run.bat"; DestDir: "{app}\windows\executors\taide\"; Flags: ignoreversion; Components: "models\llama3_point_1_taide_lx_8_q4_km";
Source: "..\..\windows\executors\gemma3-4b\run.bat"; DestDir: "{app}\windows\executors\gemma3-4b\"; Flags: ignoreversion; Components: "models\gemma_3_4b_it";
; Source: "..\..\windows\executors\phi4\run.bat"; DestDir: "{app}\windows\executors\phi4\"; Flags: ignoreversion; Components: "models\phi4_multimodal_it";

[Icons]
Name: "{group}\{cm:ProgramOnTheWeb,{#MyAppName}}"; Filename: "{#MyAppURL}"
Name: "{group}\{cm:UninstallProgram,{#MyAppName}}"; Filename: "{uninstallexe}"
Name: "{group}\Kuwa GenAI OS"; Filename: "{app}\windows\start.bat"; IconFilename: "{app}\src\multi-chat\public\images\kuwa-logo.ico"
Name: "{group}\Construct RAG"; Filename: "{app}\windows\construct_rag.bat"; IconFilename: "{app}\src\multi-chat\public\images\kuwa-logo.ico"
Name: "{group}\Model Download"; Filename: "{app}\windows\executors\download.bat"; IconFilename: "{app}\src\multi-chat\public\images\kuwa-logo.ico"
Name: "{group}\Maintenance Tool"; Filename: "{app}\windows\tool.bat"; IconFilename: "{app}\src\multi-chat\public\images\kuwa-logo.ico"
Name: "{group}\Upgrade Kuwa"; Filename: "{app}\windows\update.bat"; IconFilename: "{app}\src\multi-chat\public\images\kuwa-logo.ico"
Name: "{userdesktop}\Kuwa GenAI OS"; Filename: "{app}\windows\start.bat"; WorkingDir: "{app}\windows"; IconFilename: "{app}\src\multi-chat\public\images\kuwa-logo.ico"
Name: "{userdesktop}\Construct RAG"; Filename: "{app}\windows\construct_rag.bat"; WorkingDir: "{app}\windows"; IconFilename: "{app}\src\multi-chat\public\images\kuwa-logo.ico"

[Code]
var
  DownloadPage: TDownloadWizardPage;

function OnDownloadProgress(const Url, FileName: String; const Progress, ProgressMax: Int64): Boolean;
begin
  if Progress = ProgressMax then
    Log(Format('Successfully downloaded file to {tmp}: %s', [FileName]));
  Result := True;
end;
procedure InitializeWizard;
begin
  DownloadPage := CreateDownloadPage(SetupMessage(msgWizardPreparing), SetupMessage(msgPreparingDesc), @OnDownloadProgress);
  DownloadPage.ShowBaseNameInsteadOfUrl := True;
end;
function SplitString(const Input, Delimiter: String): TArrayOfString;
var
  S, Temp: String;
  I, Index: Integer;
begin
  SetArrayLength(Result, 0);
  S := Input;
  while Length(S) > 0 do
  begin
    I := Pos(Delimiter, S);
    if I > 0 then
    begin
      Temp := Copy(S, 1, I - 1);
      Delete(S, 1, I);
    end
    else
    begin
      Temp := S;
      S := '';
    end;
    Index := GetArrayLength(Result);
    SetArrayLength(Result, Index + 1);
    Result[Index] := Temp;
  end;
end;

function NextButtonClick(CurPageID: Integer): Boolean;
var
  HasDownloads: Boolean;
  i: Integer;
  FileListStr: String;
  FileList: TArrayOfString;
  BaseURL: String;
  TargetDir: String;
begin
  if CurPageID = wpReady then
  begin
    Result := True;

    if not Assigned(DownloadPage) then
    begin
      Log('DownloadPage is not initialized.');
      Exit;
    end;

    DownloadPage.Clear;
    HasDownloads := False;

    // Keep your original LLaMA model
    if WizardIsComponentSelected('models\llama3_point_1_taide_lx_8_q4_km') then
    begin
      DownloadPage.Add(
        'https://huggingface.co/tetf/Llama-3.1-TAIDE-LX-8B-Chat-GGUF/resolve/main/Llama-3.1-TAIDE-LX-8B-Chat-Q4_K_M.gguf?download=true',
        'models\taide\Llama-3.1-TAIDE-LX-8B-Chat-Q4_K_M.gguf',
        ''
      );
      HasDownloads := True;
    end;
    if WizardIsComponentSelected('models\gemma_3_4b_it') then
    begin
      FileListStr :=
        '.gitattributes LICENSE NOTICE README.md added_tokens.json chat_template.json config.json ' +
        'generation_config.json model-00001-of-00002.safetensors model-00002-of-00002.safetensors ' +
        'model.safetensors.index.json preprocessor_config.json processor_config.json ' +
        'special_tokens_map.json tokenizer.json tokenizer.model tokenizer_config.json';

      FileList := SplitString(FileListStr, ' ');

      BaseURL := 'https://huggingface.co/tetf/gemma-3-4b-it/resolve/main/';
      TargetDir := ExpandConstant('models\gemma3-4b\');

      if not DirExists(TargetDir) then
        ForceDirectories(TargetDir);

      for i := 0 to GetArrayLength(FileList) - 1 do
      begin
        DownloadPage.Add(
          BaseURL + FileList[i] + '?download=true',
          TargetDir + FileList[i],
          ''
        );
        HasDownloads := True;
      end;
    end;
    if WizardIsComponentSelected('models\phi4_multimodal_it') then
    begin
      FileListStr :=
        '.gitattributes CODE_OF_CONDUCT.md LICENSE README.md SECURITY.md SUPPORT.md ' +
        'added_tokens.json config.json configuration_phi4mm.py generation_config.json merges.txt ' +
        'model-00001-of-00003.safetensors model-00002-of-00003.safetensors model-00003-of-00003.safetensors ' +
        'model.safetensors.index.json modeling_phi4mm.py phi_4_mm.tech_report.02252025.pdf ' +
        'preprocessor_config.json processing_phi4mm.py processor_config.json ' +
        'sample_finetune_speech.py sample_finetune_vision.py sample_inference_phi4mm.py ' +
        'special_tokens_map.json speech_conformer_encoder.py tokenizer.json ' +
        'tokenizer_config.json vision_siglip_navit.py vocab.json';

      FileList := SplitString(FileListStr, ' ');

      BaseURL := 'https://huggingface.co/microsoft/Phi-4-multimodal-instruct/resolve/main/';
      TargetDir := ExpandConstant('models\phi4\');

      if not DirExists(TargetDir) then
        ForceDirectories(TargetDir);

      for i := 0 to GetArrayLength(FileList) - 1 do
      begin
        DownloadPage.Add(
          BaseURL + FileList[i] + '?download=true',
          TargetDir + FileList[i],
          ''
        );
        HasDownloads := True;
      end;
    end;

    if HasDownloads then
    begin
      DownloadPage.Show;

      try
        try
          DownloadPage.Download;
          Result := True;
        except
          if DownloadPage.AbortedByUser then
            Log('Aborted by user.')
          else
            SuppressibleMsgBox(AddPeriod(GetExceptionMessage), mbCriticalError, MB_OK, IDOK);
          Result := False;
        end;
      finally
        DownloadPage.Hide;
      end;
    end
    else
    begin
      Log('No files to download.');
    end;
  end
  else
    Result := True;
end;