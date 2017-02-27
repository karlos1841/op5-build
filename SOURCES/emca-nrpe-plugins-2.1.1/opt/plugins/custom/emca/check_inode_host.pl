#!/usr/bin/perl
# version 1.1

# use strict;
use lib "/opt/plugins";
#use utils qw (%ERRORS $TIMEOUT);
use Getopt::Long;
my @ret;
my @line;
my ($opt_h, $opt_H, $opt_w, $opt_c,$opt_C,$opt_s,$opt_d);
Getopt::Long::Configure("bundling");
$result=GetOptions(
        "h" => \$opt_h, "help" => \$opt_h,
        "C=s" => \$opt_C, "community=s" => \$opt_C,
        "H=s" => \$opt_H, "HOSTNAME=s" => \$opt_H,
        "s=s" => \$opt_s, "string=s" => \$opt_s,
        "w=f" => \$opt_w, "warning=f" => \$opt_w,
        "c=f" => \$opt_c, "critical=f" => \$opt_c,
	"d=s" => \$opt_d, "oid=s" => \$opt_d);


if(! $result) {
        exit $ERRORS{'UNKNOWN'};
}

if ( $opt_h ) {
        print_help();
        exit $ERRORS{'OK'};
}


my $cmd="uname";

#print "Running:\n $cmd\n";
$_ = `$cmd`;
#print "$_\n";
if($?) {
        printf("ERROR: No data received from management host.\n");
        exit($ERRORS{'CRITICAL'});
}
$os=$_;
chomp($os);
if ($os eq 'SunOS')
	{
	my $cmd="/usr/bin/df -F ufs -o i|/usr/bin/grep -v iused";
        $_ = `$cmd`;
        $return=$_;
        @raw_lines=split("\n",$return);
        my $nr_of_lines = $return =~ tr/\n//;
                for ($i=0;$i<$nr_of_lines;$i++)
                {
                $line= @raw_lines[$i];

                $line =~ s/\s+/ /g;
                @values=split(" ",$line);
                print "SunOS:@values[4] @values[1] @values[3];";
        #       print "$i $line  RAW: @raw_lines[$i]\n";
                }

	}

if ($os eq 'AIX')
    {
        my $cmd="/bin/df -i| /usr/bin/grep -v Iused";
        $_ = `$cmd`;
        $return=$_;
        @raw_lines=split("\n",$return);
        my $nr_of_lines = $return =~ tr/\n//;
                for ($i=0;$i<$nr_of_lines;$i++)
                {
                $line= @raw_lines[$i];

                $line =~ s/\s+/ /g;
                @values=split(" ",$line);
                print "AIX:@values[6] @values[4] @values[5];";
        #       print "$i $line  RAW: @raw_lines[$i]\n";
                }

    }

if ($os eq 'Linux')
    {
	my $cmd="/bin/df -ilP| /bin/grep -v Inodes";
	$_ = `$cmd`;
	$return=$_;
	@raw_lines=split("\n",$return);
	my $nr_of_lines = $return =~ tr/\n//;
		for ($i=0;$i<$nr_of_lines;$i++)
		{
		$line= @raw_lines[$i];
	
		$line =~ s/\s+/ /g;
		@values=split(" ",$line);
		print "Linux:@values[5] @values[2] @values[4];";
	#	print "$i $line  RAW: @raw_lines[$i]\n";
		}

    }


sub print_help(){
        print " This is a simple script that prints information about Inodes on Linux/AIX/SunOS system \n";
        print " Script does not work produce any warning,critical state. It must be run with nrpe and additional wrapped from monitoring server.\n";
        print " returned values: Ostype Inodes_used %Inodes_used \n";
        print "\n";

}

