#include <stdio.h>
#include <string.h>
#include <unistd.h>
#include <ctype.h>
#include <sys/utsname.h>

typedef enum
{
	OK,
	WARNING,
	CRITICAL,
	UNKNOWN
} RETURN_CODE;

void print_help(void)
{
	printf("HELP\n");
}

int sys_info(char *value)
{
	const char *info = "Unable to retrieve system information";
	struct utsname sys;
	RETURN_CODE code = OK;
	if(uname(&sys))
	{
		fprintf(stderr, "%s", info);
		code = UNKNOWN;
	}
	else
	{
		if(!strcmp(value, "os"))
			printf("%s", sys.sysname);
		else if(!strcmp(value, "hostname"))
			printf("%s", sys.nodename);
		else if(!strcmp(value, "distro"))
		{
			int c;
			FILE *file;
			file = fopen("/etc/redhat-release", "r");
			if(file)
			{
				while ((c = getc(file)) != '\n')
					putchar(c);
				fclose(file);
			}
			else
			{
				file = fopen("/etc/SuSE-release", "r");
				if(file)
				{
					while ((c = getc(file)) != '\n')
						putchar(c);
					fclose(file);
				}
				else
				{
					fprintf(stderr, "%s", info);
					code = UNKNOWN;
				}
			}
		}
		else
		{
			fprintf(stderr, "%s", info);
			code = UNKNOWN;
		}
	}
	//printf("%s\n", sys.nodename);
	//printf("%s\n", sys.release);
	//printf("%s\n", sys.version);
	//printf("%s\n", sys.machine);

	return code;
}

int main(int argc, char **argv)
{
	RETURN_CODE code = OK;
	int c;
	char *tvalue = NULL;
	char *cvalue = NULL;

	while ((c = getopt (argc, argv, "ht:c:")) != -1)
	switch(c)
	{
		case 'h':
			print_help();
			break;
		case 't':
			tvalue = optarg;
			break;
		case 'c':
			cvalue = optarg;
			if(!strcmp(tvalue, "sys"))
			{
				code = sys_info(cvalue);
			}
			break;
		case '?':
			if(optopt == 't')
				fprintf(stderr, "Option -%c requires an argument.\n", optopt);
			else if(optopt == 'c')
				fprintf(stderr, "Option -%c requires an argument.\n", optopt);
			else if(isprint (optopt))
				fprintf (stderr, "Unknown option '-%c'.\n", optopt);
			else
				fprintf(stderr, "Unknown option character '\\x%x'.\n", optopt);
			code = UNKNOWN;
			break;
		default:
			fprintf(stderr, "Option -%c is not supported.\n", c);
			code = UNKNOWN;
			break;
	}


	return code;
}
