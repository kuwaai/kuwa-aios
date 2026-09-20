#define MyAppName "Kuwa GenAI OS"
#define MyAppVersion "v0.4.2"
#define MyAppPublisher "Kuwa AI"
#define MyAppURL "https://kuwaai.tw/os/intro"
#define MyAppIcon "..\..\src\multi-chat\public\images\kuwa-logo.ico"

; RepoURL and Branch are injected by build-installer-local.js.
#ifndef RepoURL
  #define RepoURL "https://github.com/kuwaai/kuwa-aios.git"
#endif
#ifndef RepoHTTPSURL
  #define RepoHTTPSURL "https://github.com/kuwaai/kuwa-aios.git"
#endif
#ifndef Branch
  #define Branch "main"
#endif
#define Gemma4ModelURL "https://huggingface.co/google/gemma-4-E2B-it-qat-q4_0-gguf/resolve/main/gemma-4-E2B_q4_0-it.gguf?download=true"
#define PortableGitURL "https://github.com/git-for-windows/git/releases/download/v2.45.1.windows.1/PortableGit-2.45.1-64-bit.7z.exe"

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
OutputBaseFilename=Kuwa-AIOS-Online-Installer
SetupIconFile={#MyAppIcon}
Compression=lzma
SolidCompression=yes
WizardStyle=modern


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
Name: "product\Kuwa"; Description: "Kuwa"; Types:  full compact custom ;Flags: fixed; ExtraDiskSpaceRequired:9850003637;

//Name: "product\Kuwa\Huggingface"; Description: "Huggingface Executor Runtime"; Types: full compact custom;

//Name: "product\Kuwa\LLaMA_CPP"; Description: "LLaMA_CPP Executor Runtime"; Types: custom;
//Name: "product\Kuwa\LLaMA_CPP\CPU"; Description: "CPU"; Types: custom; Flags: exclusive;
//Name: "product\Kuwa\LLaMA_CPP\CUDA_12_4"; Description: "CUDA v12.4 (Default)"; Types: full compact custom; Flags: exclusive;

//Name: "product\n8n"; Description: "n8n"; Types: full custom;ExtraDiskSpaceRequired:536870912;
//Name: "product\langflow"; Description: "Langflow"; Types: full custom;ExtraDiskSpaceRequired:536870912;

Name: "models"; Description: "Model Selection"; Types: full custom;Flags: fixed;
Name: "models\gemma_4_e2b_q4_0"; Description: "Gemma4 E2B Q4"; Types: full compact custom; ExtraDiskSpaceRequired:3570000000;

[Icons]
Name: "{group}\{cm:ProgramOnTheWeb,{#MyAppName}}"; Filename: "{#MyAppURL}"
Name: "{group}\{cm:UninstallProgram,{#MyAppName}}"; Filename: "{uninstallexe}"
Name: "{group}\Kuwa GenAI OS"; Filename: "{app}\windows\launcher.bat"; IconFilename: "{app}\src\multi-chat\public\images\kuwa-logo.ico"
Name: "{group}\Construct RAG"; Filename: "{app}\windows\construct_rag.bat"; IconFilename: "{app}\src\multi-chat\public\images\kuwa-logo.ico"
Name: "{group}\Maintenance Tool"; Filename: "{app}\windows\repair.bat"; IconFilename: "{app}\src\multi-chat\public\images\kuwa-logo.ico"
Name: "{group}\Upgrade Kuwa"; Filename: "{app}\windows\update.bat"; IconFilename: "{app}\src\multi-chat\public\images\kuwa-logo.ico"
Name: "{userdesktop}\Kuwa GenAI OS"; Filename: "{app}\windows\launcher.bat"; WorkingDir: "{app}\windows"; IconFilename: "{app}\src\multi-chat\public\images\kuwa-logo.ico"
Name: "{userdesktop}\Construct RAG"; Filename: "{app}\windows\construct_rag.bat"; WorkingDir: "{app}\windows"; IconFilename: "{app}\src\multi-chat\public\images\kuwa-logo.ico"

[Run]
Filename: "{app}\windows\launcher.bat"; WorkingDir: "{app}\windows"; Flags: postinstall shellexec nowait skipifsilent; Check: FileExists(ExpandConstant('{app}\windows\launcher.bat'))

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

function DeleteInstalledFilesExcept(const Directory, KeepFile: String): Boolean;
var
  FindData: TFindRec;
  FilePath: String;
begin
  Result := True;
  if not FindFirst(Directory + '\*', FindData) then
    Exit;
  try
    repeat
      if (FindData.Name <> '.') and (FindData.Name <> '..') then
      begin
        FilePath := Directory + '\' + FindData.Name;
        if (FindData.Attributes and FILE_ATTRIBUTE_DIRECTORY) <> 0 then
        begin
          DeleteInstalledFilesExcept(FilePath, KeepFile);
          RemoveDir(FilePath);
        end
        else if CompareText(FilePath, KeepFile) <> 0 then
          DeleteFile(FilePath);
      end;
    until not FindNext(FindData);
  finally
    FindClose(FindData);
  end;
end;

procedure CurUninstallStepChanged(CurUninstallStep: TUninstallStep);
begin
  if CurUninstallStep = usUninstall then
    DeleteInstalledFilesExcept(ExpandConstant('{app}'),
      ExpandConstant('{app}\src\multi-chat\database\database.sqlite'));
end;

function PrepareGit(var GitPath: String): Boolean;
var
  PortableGitArchive, PortableGitDir, Args: String;
  RC: Integer;
begin
  Result := False;
  GitPath := ExpandConstant('{autopf}\Git\cmd\git.exe');
  if FileExists(GitPath) then
  begin
    Result := True;
    Exit;
  end;

  GitPath := ExpandConstant('{pf}\Git\cmd\git.exe');
  if FileExists(GitPath) then
  begin
    Result := True;
    Exit;
  end;

  PortableGitArchive := ExpandConstant('{tmp}\PortableGit-2.45.1-64-bit.7z.exe');
  PortableGitDir := ExpandConstant('{tmp}\PortableGit-2.45.1-64-bit');
  if not FileExists(PortableGitArchive) then
    if not Exec(ExpandConstant('{sys}\curl.exe'), '-L --fail --silent --show-error "{#PortableGitURL}" -o "' + PortableGitArchive + '"', '', SW_HIDE, ewWaitUntilTerminated, RC) or (RC <> 0) then
      Exit;

  if not DirExists(PortableGitDir) then
  begin
    ForceDirectories(PortableGitDir);
    Args := '-o"' + PortableGitDir + '" -y';
    if not Exec(PortableGitArchive, Args, '', SW_HIDE, ewWaitUntilTerminated, RC) or (RC <> 0) then
      Exit;
  end;

  GitPath := PortableGitDir + '\cmd\git.exe';
  Result := FileExists(GitPath);
end;

function CloneRepository(const AppDir: String): Boolean;
var
  CloneDir, RepoDir, Url, GitPath, Args: String;
  RC: Integer;
begin
  Result := False;
  CloneDir := ExpandConstant('{tmp}\kuwa-git-clone');
  RepoDir := CloneDir;
  Url := '{#RepoHTTPSURL}';
  if not PrepareGit(GitPath) then
    Exit;

  DelTree(CloneDir, True, True, True);
  if not Exec(GitPath, 'clone --branch "{#Branch}" --depth 1 "' + Url + '" "' + CloneDir + '"', '', SW_HIDE, ewWaitUntilTerminated, RC) or (RC <> 0) then
    Exit;

  if not DirExists(RepoDir + '\.git') then
  begin
    DelTree(CloneDir, True, True, True);
    Exit;
  end;

  ForceDirectories(AppDir);
  Args := '/c robocopy "' + RepoDir + '" "' + AppDir + '" /E /COPY:DAT /DCOPY:DAT /R:0 /W:0 /NFL /NDL /NJH /NJS /NP';
  Result := Exec(ExpandConstant('{sys}\cmd.exe'), Args, '', SW_HIDE, ewWaitUntilTerminated, RC) and
    (RC <= 7) and FileExists(AppDir + '\windows\launcher.bat') and
    FileExists(AppDir + '\.git\HEAD');
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
  ModelPath: String;
begin
  if CurStep = ssPostInstall then
  begin
    if not CloneRepository(ExpandConstant('{app}')) then
    begin
      MsgBox('Kuwa repository clone failed. Git metadata could not be installed. Check the repository URL, branch, and network access.', mbError, MB_OK);
      Abort;
    end;

    ModelPath := ExpandConstant('{app}\windows\executors\gemma4-e2b\gemma-4-E2B_q4_0-it.gguf');
    ForceDirectories(ExtractFileDir(ModelPath));
    if not FileExists(ModelPath) then
    begin
      if not FileExists(ExpandConstant('{tmp}\models\gemma-4-E2B_q4_0-it.gguf')) or
         not CopyFile(ExpandConstant('{tmp}\models\gemma-4-E2B_q4_0-it.gguf'), ModelPath, False) then
      begin
        MsgBox('Gemma 4 E2B model installation failed.', mbError, MB_OK);
        Abort;
      end;
    end;

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
  if CurPageID = wpReady then
  begin
    DownloadPage.Clear;
    if WizardIsComponentSelected('models\gemma_4_e2b_q4_0') and
       not FileExists(ExpandConstant('{tmp}\models\gemma-4-E2B_q4_0-it.gguf')) then
    begin
      DownloadPage.Add(
        '{#Gemma4ModelURL}',
        'models\gemma-4-E2B_q4_0-it.gguf',
        ''
      );
      DownloadPage.Show;
      try
        try
          DownloadPage.Download;
          Result := True;
        except
          if DownloadPage.AbortedByUser then
            Log('Download aborted by user.')
          else
            SuppressibleMsgBox(AddPeriod(GetExceptionMessage), mbCriticalError, MB_OK, IDOK);
          Result := False;
        end;
      finally
        DownloadPage.Hide;
      end;
    end
    else
      Result := True;
  end
  else if CurPageID = AccountPage.ID then
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