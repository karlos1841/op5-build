################################################################################
#
# nrpe configuration file
#

# the port nrpe should listen on
server_port=5666

# use this variable to force nrpe to bind to a specific IP. Default is to
# bind all.
#server_address=127.0.0.1

# Add the IP of you OP5 Monitor server on this line
# multiple addresses can be separated with , ie: allowed_hosts=1.2.3.4,1.2.3.5
#
#VW
allowed_hosts=127.0.0.1,10.48.248.35,10.100.100.59

nrpe_user=op5
nrpe_group=op5
debug=0
command_timeout=60

# In order to make remote config with conf_nrpe work, you need to 
# create the following directory. It needs to be read/writeable by
# nrpe_user specified above. 
# All command definitions should be placed in the 'include_dir'
# NOTE: files in 'include_dir' must have a '.cfg' suffix.
include_dir=/etc/nrpe.d
dont_blame_nrpe=1
