# PA setup for CS department VMs

These instructions describe how to configure PA for a CS department
VM.  These instructions are designed to supplement the main PA readme
at [README.md](./README.md).  Read this document first, then refer to
the readme as necessary.

## VM configuration

Before continuing, you should ensure that your VM has the following
configuration (ask Tstaff to configure):
 1. Allow outgoing HTTP, HTTPS, and SSH traffic
 2. Allow incoming HTTP and HTTPS
 3. /tmp partition mounted using a `tmpfs` volume (not a separate disk
    partition, as is the default VM configuration; details below)
   
For the tmpfs mount, you can temporarily set this up by running the
following:
```
# mount -t tmpfs none /tmp
```
However, this change will not be permanent until you request that
Tstaff to update your `/etc/fstab`.  You cannot simply modify the file
yourself, or Tstaff's automatic configuration scripts will rewrite it
within 24 hours.  Instead, update the file yourself and then **send a
copy of that file to Tstaff** asking them to replace it.

## Initial system setup 

1. Create a user and group for PA to use, and add it to the group used
   by your webserver.  For this guide, we will use `peteramati`.
   
2. Install mariadb and php-fpm. 
    To configure php-fpm, edit `/etc/php/8.2/fpm/pool.d/www.conf` to replace the line
    ```
    listen = /run/php/php8.2-fpm.sock
    ```
    with the line 

    ```
    listen = 127.0.0.1:9000
    ```
    Alternately, use the default unix domain socket instead of localhost port 9000 at the corresponding step in the main PA readme.
   
2. Install docker (package `docker.io`).  To run well on department
   VMs, you should alter at least the following settings (example
   configuration below):
    - The department VMs make `/var` very small, so you should instead
      configure docker to write containers and images to `/home`
	- Some of docker's internal IP ranges overlap with Brown's guest
      Wifi, so you should configure it to use a different IP prefix
	  
    To make these changes, add the following to
    `/etc/docker/daemon.json` and restart docker:
	
	```
    {
      "data-root": "/home/docker",
      "bip": "172.23.0.1/24",
      "default-address-pools": [
        { "base": "172.24.0.0/13", "size": 24 }
      ]
    }
	```

3. Clone the `cs300` branch of the peteramati repo and copy it
   somewhere you want peteramati to live (eg. `/opt/peteramati/www-s25`)
   
4. Set the permissions on the PA web directory.  The goal is for the
   owner to be `peteramati` and group `www-data`, and for the group to
   have write permissions in the directory to create new files:

    ```
    # chown -R peteramati:www-data /opt/peteramati/www-s25
    # chmod g+s /opt/peteramati/www-s25
    ```
	
5. In PA's main directory, create and set permissions on the `log` and
   `repo` dirs, which contains students checked-out git repositories.
   These directories need to be readable by several users, and
   writable by `www-data`:
   
   ```
   # cd /opt/peteramati/www-s25
   # mkdir repo log
   # chown -R www-data:www-data repo log
   # chmod 755 repo log
   ```

5. Follow steps 1-7 of the main PA readme to create the database,
   configure your webserver, and build the jail.  Some notes:
    - On step 2, when configuring `conf/options.php`, you will need to
      provide one or more JSON files in `$Opt["psetsConfig"]`.  These
      files need to be readable by `www-data`.  **PA will not load at
      all unless you have a basic configuration present.**  For an
      example, see CS300's PA config repo.
    - On step 3, the apache config file to edit is in `/etc/apache2/sites-available/default-ssl.conf`. You also want to edit `/etc/apache2/sites-available/000-default.conf` to add the line `Redirect permanent / https://<xxx>.cs.brown.edu`.
    - Then, follow [these instructions](https://www.digitalocean.com/community/tutorials/how-to-secure-apache-with-let-s-encrypt-on-ubuntu-20-04) to enable HTTPS via Let's Encrypt.
    - For the `ProxyPass` line to work, run `a2enmod proxy` and `a2enmod proxy_http` as root and restart apache (`systemctl restart apache2.service`).
    - On step 5, to create an OAuth token, go to Developer settings > OAUth apps
      on your course Github org's page.
	- On step 6, you can set the user's password like this:
	```
	$ lib/runsql.sh --set-password email@example.com some_password
	```
	You will also want to create at least one test user (with
	`roles=0`) so that you can submit work as a student.
   
At this point, you should be able to log into PA as both an admin and
student, but you should not be able to run anything.  If you log in as
a student and load a repository, you should be able to see their
commits.
   
## Set up the jail environment

After PA is configured, we need to configure the grading VM with a
copy of the course's development environment.  The way we have done
this in CS 300 is to create a copy of the container's filesystem on
the grading VM, which serves as a template (or "skeleton", in PA
terms).  When grading a student's submission, PA's jail program
(`pa-jail`, a setuid binary) creates a jail environment in which to
run the student's submission (via chroot, and an overlay mount so that
they can't modify the skeleton).  To set this up, do the following:
   
   
### Create the skeleton filesystem
   
2. On an x86-64 system (either your VM, or some other system), build
   your course container image (`./cs300-run-docker build-image`) and start the container (eg. run
   `./cs300-run-docker` once, and exit).  Once you are satisfied it
   works properly and has everything installed, run the following
   (where `cs300-container` is the name of your container):
   
```
$  docker container export cs300-container | gzip > cs300-devenv-s25.tar.gz
```

8. On the grading VM, create the following directories (as root):

```
/opt/peteramati/jail
/opt/peteramati/jailbind
/opt/peteramati/jail-target
```

9. Unpack the container tarball to a directory under `/opt/peteramati/jail` (eg. `cs300-s25`), like this:

```
# mkdir /opt/peteramati/jail/cs300-s25
# tar -xpf cs300-devenv-s25.tar.gz -C /opt/peteramati/jail/cs300-s25
```

### Configure the VM to use the jail

Now that the filesystem has been created, we need to configure the VM
and the jail

1. Create a user for the jail (eg. `jail300user`) using `useradd`.  If
   you want to simplify configuration for the next few steps, make
   this the same username your container environment uses
   (eg. `cs300-user`).  The user should have a home directory, but
   should not have a password set.  Student code will run as this
   user, inside a chroot.

2. On the grading VM, create and configure the file
    `/etc/pa-jail.conf` to tell `pa-jail` about the jail directory:
	
    ```
    enablejail /jails/*
    enablejail /jails-dev/*
    enableskeleton /opt/peteramati/jail/*
    enablejail /opt/peteramati/jailbind
    ```
	
3. Wherever you store your `psets.json` create a files
   `jfiles-grading.txt` with the following info:

	```
   /dev/fd
   /dev/null
   /dev/ptmx
   /dev/pts
   /dev/tty
   /dev/urandom
   ```

3. In your `psets.json`, configure the "_defaults" parameter with the
    following options (updating the path to `jfiles-grading.txt` to
    match the file you created earlier):

    ```
    "_defaults": {
        "run_username": "jail300user",
     	"run_dirpattern": "/jails/repo${REPOID}.pset${PSET}",
        "run_jailfiles": "/opt/peteramati/conf/cs300-s24/jfiles-grading.txt",
	    "run_skeletondir": "/opt/peteramati/jail/cs300",
    	"run_binddir": "/opt/peteramati/jailbind",
    }

   ```


PA should now be configured to use the jail.  Now we need to slightly
alter the container environment to run on the grading VM.

### Configuring the skeleton filesystem

The skeleton filesystem (previously a container filesystem) needs some
slight alterations to run with `pa-jail` (and without docker).  The
most gnarly part is that we need to ensure that the jail user has the
same UID and GID inside the jail and outside, so the permissions are
correct.

1. Copy the `/etc/passwd` entry for `jail300user` from the grading VM:

    ```
	# cat /etc/passwd | grep jail300user
	jail300user:x:1000:1004:CS 300 Jail User,,,:/home/jail300user:/bin/bash
    ```
	 
    In this example, `jail300user` has UID 1000 and GID 1004.

2. Open up `/etc/passwd` in the skeleton filesystem
   (eg. `/opt/peteramati/jail/cs300-s25/etc/passwd`) and change the
   following:
    - Change `cs300-user` to `jail300user`
	- Change the UID for `jail300user` to match whatever you found
      from step 1 (eg. 1000)
    - Change the default GID for `jail300user` to match whatever you
      found from step 1 (eg. 1004)
	- Change the home dir to `/home/jail300user`

3. Open up `/etc/group` and change the following:
   - Change `cs300-user` to `jail300user`
   - Change the GID for `jail300user` to match whichever value you
     found from step 1 (eg. 1004)
	
3. Rename `/home/cs300-user` in the skeleton directory to match the
   new username:
   ```
   # mv /opt/peteramati/jail/cs300-s25/home/cs300-user /opt/peteramati/jail/cs300-s25/home/jail300user
   ```

4. Finally, add an `/etc/resolv.conf` to the skeleton filesystem:

    ```
    # echo "nameserver 1.1.1.1" > /opt/peteramati/jail/cs300-s25/etc/resolv.conf"
    ```

You should now be able to click pset buttons in PA and see the
results!  To test things out, a good test "pset" is to just run a
shell and poke around.
