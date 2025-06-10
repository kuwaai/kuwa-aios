#define MyAppName "Kuwa GenAI OS"
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
OutputBaseFilename=Kuwa-AIOS-Full-Installer-TW
SetupIconFile={#MyAppIcon}
Compression=lzma
SolidCompression=yes
WizardStyle=modern
DiskSpanning=yes
DiskSliceSize="2000000000"

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
Name: "product"; Description: "Product Components"; Types: full compact custom;Flags: fixed;
Name: "product\Kuwa"; Description: "Kuwa"; Types:  full compact custom ;Flags: fixed;

//Name: "product\Kuwa\Huggingface"; Description: "Huggingface Executor Runtime"; Types: full compact custom;

//Name: "product\Kuwa\LLaMA_CPP"; Description: "LLaMA_CPP Executor Runtime"; Types: custom;
//Name: "product\Kuwa\LLaMA_CPP\CPU"; Description: "CPU"; Types: custom; Flags: exclusive;
//Name: "product\Kuwa\LLaMA_CPP\CUDA_12_4"; Description: "CUDA v12.4 (Default)"; Types: full compact custom; Flags: exclusive;

//Name: "product\n8n"; Description: "n8n"; Types: full custom;ExtraDiskSpaceRequired:536870912;
//Name: "product\langflow"; Description: "Langflow"; Types: full custom;ExtraDiskSpaceRequired:536870912;

Name: "models"; Description: "Model Selection"; Types: full custom;Flags: fixed;
Name: "models\gemma_3_1b_it_q4_0"; Description: "Gemma3 1B QAT Q4"; Types: full compact custom;
Name: "models\llama3_point_1_taide_lx_8_q4_km"; Description: "Llama3.1 TAIDE LX-8_Q4_KM"; Types: custom;

[Files]
Source: "..\..\*"; DestDir: "{app}"; Flags: ignoreversion recursesubdirs createallsubdirs; \
    Excludes: "gemma3-4b\run.bat,gemma3-1b\run.bat,taide\run.bat,*.gguf,windows-setup-files\*.exe,windows\packages\*,windows-setup-files\*.bin,node_modules\*,vendor\*"; \
    Permissions: users-full; Components: "product\Kuwa"

Source: "..\..\.git\*"; DestDir: "{app}\.git"; Flags: ignoreversion recursesubdirs createallsubdirs; \
    Permissions: users-full; Components: "product\Kuwa"

Source: "..\..\windows\executors\gemma3-1b\gemma-3-1b-it-q4_0.gguf"; DestDir: "{app}\windows\executors\gemma3-1b\"; Flags: ignoreversion; Components: "models\gemma_3_1b_it_q4_0"
Source: "..\..\windows\executors\gemma3-1b\run.bat"; DestDir: "{app}\windows\executors\gemma3-1b\"; Flags: ignoreversion; Components: "models\gemma_3_1b_it_q4_0"

Source: "..\..\windows\executors\taide\Llama-3.1-TAIDE-LX-8B-Chat-Q4_K_M.gguf"; DestDir: "{app}\windows\executors\taide\"; Flags: ignoreversion; Components: "models\llama3_point_1_taide_lx_8_q4_km"
Source: "..\..\windows\executors\taide\run.bat"; DestDir: "{app}\windows\executors\taide\"; Flags: ignoreversion; Components: "models\llama3_point_1_taide_lx_8_q4_km"

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

[Run]
Filename: "{app}\windows\start.bat"; Flags: shellexec; Components: "product\Kuwa"

[Code]
var
  DownloadPage: TDownloadWizardPage;
  AccountPage: TInputQueryWizardPage;
  AutoLoginCheckBox: TNewCheckBox;
  Username, Password, ConfirmPass: String;
  AutoLoginValue: String;

function OnDownloadProgress(const Url, FileName: String; const Progress, ProgressMax: Int64): Boolean;
begin
  if Progress = ProgressMax then
    Log(Format('Successfully downloaded file to {tmp}: %s', [FileName]));
  Result := True;
end;

procedure InitializeWizard;
begin
  AccountPage := CreateInputQueryPage(wpUserInfo,
    'Create Root Account',
    'Please enter account details',
    'Enter an email address and password to create the root account.');

  AccountPage.Add('Email:', False); 
  AccountPage.Add('Password:', True); 
  AccountPage.Add('Confirm Password:', True); 

  AutoLoginCheckBox := TNewCheckBox.Create(WizardForm);
  AutoLoginCheckBox.Parent := AccountPage.Surface;
  AutoLoginCheckBox.Top := AccountPage.Edits[2].Top + AccountPage.Edits[2].Height + 12;
  AutoLoginCheckBox.Left := AccountPage.Edits[2].Left;
  AutoLoginCheckBox.Width := 300;
  AutoLoginCheckBox.Caption := 'Single User Mode';
  AutoLoginCheckBox.Checked := False; 
  DownloadPage := CreateDownloadPage(SetupMessage(msgWizardPreparing), SetupMessage(msgPreparingDesc), @OnDownloadProgress);
  DownloadPage.ShowBaseNameInsteadOfUrl := True;
end;
procedure CurStepChanged(CurStep: TSetupStep);
var
  InitFile: String;
  InitContent: String;
  Email: String;
begin
  if CurStep = ssPostInstall then
  begin
    Email := AccountPage.Values[0];
    Password := AccountPage.Values[1];
    ConfirmPass := AccountPage.Values[2];

    if (Email = '') or (Password = '') or (ConfirmPass = '') then
    begin
      Abort;
    end;
    
    if not (Password = ConfirmPass) then
    begin
      Abort;
    end;

    if AutoLoginCheckBox.Checked then
      AutoLoginValue := 'autologin=true' + #13#10
    else
      AutoLoginValue := 'autologin=false' + #13#10;

    InitContent := 'username=' + Email + #13#10 +
                   'password=' + Password + #13#10 +
                   AutoLoginValue;

    InitFile := ExpandConstant('{app}\windows\init.txt');

    SaveStringToFile(InitFile, InitContent, False);
  end;
end;
function IsValidEmail(strEmail: String): Boolean;
var
  nSpace: Integer;
  nAt: Integer;
begin
  strEmail := Trim(strEmail);
  nSpace := Pos(' ', strEmail);
  nAt := Pos('@', strEmail);

  // Valid if: no spaces, has an '@' not at start or end
  Result := (nSpace = 0) and (nAt > 1) and (nAt < Length(strEmail));
end;
function NextButtonClick(CurPageID: Integer): Boolean;
begin
  if CurPageID = AccountPage.ID then
  begin
    Username := AccountPage.Values[0];
    Password := AccountPage.Values[1];
    ConfirmPass := AccountPage.Values[2];

    Result := True; // Default result is True, but will be set to False if any validation fails

    if (Username = '') or (Password = '') then
    begin
      MsgBox('Both username and password are required.', mbError, MB_OK);
      Result := False;
    end;

    if not (ConfirmPass = Password) then
    begin
      MsgBox('Password mismatch!', mbError, MB_OK);
      Result := False;
    end;

    if (ConfirmPass = '') then
    begin
      MsgBox('Please repeat your password to confirm', mbError, MB_OK);
      Result := False;
    end;

    if not IsValidEmail(Username) then
    begin
      MsgBox('Please enter a valid email address.', mbError, MB_OK);
      Result := False;
    end;
  end
  else
    Result := True;
end;