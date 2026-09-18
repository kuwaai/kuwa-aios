<?php

namespace App\Jobs;

class WarningMessages
{
    const DEFAULT_ERROR = '[Sorry, something is broken, please try again later!]';
    const NO_EXECUTOR = "[Sorry, There're no machine to process this LLM right now! Please report to Admin or retry later!]";
    const EMPTY_RESPONSE = '[Oops, the LLM returned empty message, please try again later or report to admins!]';
    const KUWA_WARNING = '[Regarding the introduction of Kuwa, please refer to the information on the official kuwaai.org website.]';
    const KUWA_WARNING_ZH = '[有關Kuwa的相關說明，請以 kuwaai.org 官網的資訊為準。]';
}

