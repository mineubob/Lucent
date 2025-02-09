<?php

namespace Lucent\Logging;


class ConsoleColors {
    // Foreground colors
    const string FG_BLACK = "\033[0;30m";
    const string FG_RED = "\033[0;31m";
    const string FG_GREEN = "\033[0;32m";
    const string FG_YELLOW = "\033[0;33m";
    const string FG_BLUE = "\033[0;34m";
    const string FG_MAGENTA = "\033[0;35m";
    const string FG_CYAN = "\033[0;36m";
    const string FG_WHITE = "\033[0;37m";

    // Background colors
    const string BG_BLACK = "\033[40m";
    const string BG_RED = "\033[41m";
    const string BG_GREEN = "\033[42m";
    const string BG_YELLOW = "\033[43m";
    const string BG_BLUE = "\033[44m";
    const string BG_MAGENTA = "\033[45m";
    const string BG_CYAN = "\033[46m";
    const string BG_WHITE = "\033[47m";

    // Styles
    const string RESET = "\033[0m";
    const string BOLD = "\033[1m";
    const string DIM = "\033[2m";
    const string ITALIC = "\033[3m";
    const string UNDERLINE = "\033[4m";
}