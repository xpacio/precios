#include <stdio.h>
#include <sys/stat.h>
#include <utime.h>
#include <unistd.h>
#include <poll.h>
#include <termios.h>

FILE* __acrt_iob_func(unsigned n) {
    if (n == 0) return stdin;
    if (n == 1) return stdout;
    if (n == 2) return stderr;
    return NULL;
}

int _stat64(const char* path, struct stat* buf) {
    return stat(path, buf);
}

int _kbhit(void) {
    struct pollfd pfd = { .fd = 0, .events = POLLIN };
    return poll(&pfd, 1, 0) > 0;
}

int _getch(void) {
    struct termios old, new;
    tcgetattr(0, &old);
    new = old;
    new.c_lflag &= ~(ICANON | ECHO);
    tcsetattr(0, TCSANOW, &new);
    int ch = getchar();
    tcsetattr(0, TCSANOW, &old);
    return ch;
}

int _utime(const char* path, struct utimbuf* times) {
    return utime(path, times);
}
