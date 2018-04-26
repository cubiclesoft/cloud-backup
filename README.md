Cloud Backup
============

A flexible, powerful, and easy to use rolling incremental backup system that pushes collated, compressed, and encrypted data to online cloud storage services, local attached storage, and network storage.

Cloud Backup is third generation backup software that works for every major platform that matters.

[![Donate](https://cubiclesoft.com/res/donate-shield.png)](https://cubiclesoft.com/donate/)

Features
--------

* All the [standard things you need in a backup system](https://github.com/cubiclesoft/cloud-backup/blob/master/docs/minimum-requirements-for-backup-software.md).
* Transparent compression and encryption before sending data to the storage provider.
* Supports these cloud storage services:  [OpenDrive](https://www.opendrive.com/) and [Cloud Storage Server](https://github.com/cubiclesoft/cloud-storage-server).
* Supports local attached storage.
* Block-based storage for [major reductions in the number of API calls made](https://github.com/cubiclesoft/cloud-backup/blob/master/docs/cloud-backup-design-spec-and-benchmarks.md).
* Also has a liberal open source license.  MIT or LGPL, your choice.
* Designed for relatively painless integration into your environment.
* Sits on GitHub for all of that pull request and issue tracker goodness to easily submit changes and ideas respectively.

Getting Started
---------------

Download or clone the latest software release.  If you do not have PHP installed, then download and install the command-line (CLI) version for your OS (e.g. 'apt install php-cli' on Debian/Ubuntu).  Windows users try [Portable Apache + PHP + Maria DB](https://github.com/cubiclesoft/portable-apache-maria-db-php-for-windows).

From a command-line, run:

```
php configure.php
```

The installer will ask a series of questions that will configure the backup.  The configuration tool may be re-run at any time - although some options such as service selection can't be changed.  Be sure to take advantage of the e-mail notification and file monitoring features.

After the backup has been configured, run it:

```
php backup.php
```

If you encounter any problems, you can test e-mail notifications and service connectivity respectively with these two commands:

```
php test_notifications.php
php test_service.php
```

Once the first backup completes, be sure to verify that it is functioning properly by running:

```
php verify.php
```

Once everything about the backup looks good, which might take several days of running manual backups and verifications, use your system's built-in task scheduler to run 'backup.php' on a regular basis.  Under Windows, use [Task Scheduler](http://windows.microsoft.com/en-US/windows/schedule-task).  Under most other OSes, use [cron](https://help.ubuntu.com/community/CronHowto).

Install and configure a second copy of Cloud Backup for a different backup location.  Good backups have one installation for an on-site backup (e.g. an attached hard drive) and one installation that uses an off-site cloud backup service.  If the location where the backup tools are located is in the backup path, be sure to exclude each installation from the other one or else they will constantly back up the others' cached files.

Go into the directories where the backup software is installed.  Locate the file called 'config.dat'.  This is a plain text JSON file containing your backup configuration, but, more importantly, it also contains your encryption keys.  Without the file, the backup data is useless.  Copy the files to a couple of external thumbdrives and put those thumbdrives somewhere safe.  A safe-deposit box at a bank and a decent hiding place at home/work can do wonders here.  Cloud Backup makes it possible to accurately recover data even in the face of disaster scenarios.

At this point, Cloud Backup is set up.  Adding a reminder to a calendar to verify backups on a monthly basis is highly recommended.  To verify a backup, run:

```
php verify.php
```

Verification is easy and confirms that the backup data still looks valid.  Verification spot-checks the backup and displays vital statistics about the files database that tracks details about the directories and files in the backup.

Example Prebackup Scripts
-------------------------

* [Database export via CSDB](https://github.com/cubiclesoft/csdb/blob/master/docs/csdb_queries.md#generic-database-exportimport)

Restoring Data
--------------

In the event that data needs to be restored from the backup, first verify the backup (sanity check):

```
php verify.php
```

Then start the restoration shell:

```
php restore.php
```

After retrieving the information for a specific backup, 'restore.php' asks which backup to load the view of and, once loaded, presents a shell-like command-line interface to access the backup.  This extensible interface has the following commands:

* cd, chdir - Change directory.
* dir, ls - List current directory.
* restore - Restores one or more files or directories to a 'restore' subdirectory where the backup software is located.
* groups, users - Show a unique list of groups/users (relevant for *NIX OSes only).
* mapgroup, mapuser - Change all files and directories matching one specific group/user to another (relevant for *NIX OSes only).  Temporarily affects groups and users in the SQLite database so that restored files correctly map to the available groups and users on the host.
* stats - Show database statistics.
* help - Show the help screen.
* exit, quit - Leave the shell.

Depending on how much data is being restored, the process can, of course, take a while.

Defragmenting
-------------

A good rule of thumb is to defragment backups once a year.  Defragmentation only affects shared blocks.  Non-shared blocks are self-defragmenting.

To defragment a backup, manually run:

```
php backup.php -d
```

See [Cloud Backup Design Specifications and Benchmarks](https://github.com/cubiclesoft/cloud-backup/blob/master/docs/cloud-backup-design-spec-and-benchmarks.md) for more details on how the backup system works with regards to shared blocks.  As smaller files are added, removed, and changed, the shared block numbers they point to also change.  This, over time, implicitly fragments shared blocks that were created earlier.  Each shared block still contains the original data but fewer and fewer references to the shared block will exist.

The defragmentation procedure determines if a shared block has space available greater than two times the small file limit (default 2MB) and then both schedules the shared block for deletion and removes the associated files from the database.  The rest of the backup then proceeds normally, which perceives the aforementioned deleted database entries as new files, which will be placed into new shared blocks.  The end result is an incremental that eventually makes fairly significant changes once it merges into the base.  How long that takes, of course, depends on how many incrementals are kept around and the frequency of backups.

Adding Cloud Services
---------------------

To be included in Cloud Backup, a cloud storage service must meet four criteria:

* A publicly available, well-documented, officially supported RESTful API.  Preferably one that is also complete, stable, and without daily request limits on the API itself.
* Be affordable.  There's no hard-and-fast requirement here other than the cost must be a fixed monthly rate NOT dependent upon how much data is stored.  A free option is nice for integration purposes but not required.
* Unlimited data storage.  Read their EULA for the fine-print.
* Unlimited data transfer.  Unlimited transfer is not the same as unlimited bandwidth.  Speed doesn't matter as long as all of the data to back up eventually gets backed up.

Once a cloud storage service meets the minimum criteria, two things have to be built:  A general-purpose PHP SDK (a PHP class) for the cloud service and then a specialized interface (another PHP class) that handles the communication between Cloud Backup and the service's PHP SDK.

And then, of course, it takes time to test the whole thing to make sure it all works properly.

If a service changes its policies so that the above list is no longer true, then support will be dropped.  The following services have been retired:  Amazon Cloud Drive.

Other Thoughts
--------------

[Cloud Storage Server](https://github.com/cubiclesoft/cloud-storage-server) is a self-hosted cloud storage API that was developed to create a really nice baseline that plays nicely with the Cloud Backup software.  Useful for backing up your data to your neighbors' or friends' residences.

There are hundreds of backup/sync software products out there.  I've evaluated quite a few of them.  Besides Cloud Backup (this software), only two other products are, in my opinion, worth your attention:  [rclone](http://rclone.org/) and [Restic](https://restic.github.io/).  (Restic appears to use rclone or some variant of it under the hood.)  They meet most of the criteria for good backup/sync software and have a decent following.  Be aware that those products rely on deltas, which I've found serious fault with.

Check out the [DataHoarder](https://www.reddit.com/r/DataHoarder/) subreddit.  It's fun to watch people with 100TB+ attempting to back up their data to various places.

Finally, OpenDrive is a bit weird in that the service is only occasionally mentioned on the Internet, rarely reviewed, and can even be hard to find, but happens to have a complete, published API (quite rare), the API has no rate limits (rare), and a non-restrictive EULA (extremely rare).  They do have some occasional API connectivity/stability/uptime issues (Update 2018:  Which seem to finally be fixed?) but I just find it very bizarre that they aren't mentioned/reviewed more frequently - maybe it is the $13/month that drives reviewers away.  However, OpenDrive is an excellent choice for businesses that want to securely back up their data off-site via Cloud Backup - mostly because of that EULA.  OpenDrive was the first service to be included in Cloud Backup.  It took a long time to find them too because Google Search kept burying OpenDrive results.
