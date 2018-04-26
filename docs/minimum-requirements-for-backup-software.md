Minimum Requirements For Backup Software
========================================

There are hundreds of backup solutions out there.  Cloud Backup (this software) is an excellent choice.  Use the following checklist as a baseline when choosing a backup solution.

Open Source
-----------

Your data is king.  Proprietary, closed source, and even homegrown solutions should, as a result, be avoided for quite a few reasons.  If the source code to the software is not published somewhere public (e.g. GitHub) and can't be evaluated, then there is no way to confirm that the software actually does what it says it does and the software should not be trusted with your data.  We live in a world where people say one thing happens but something completely different is the truth.

The homegrown software comment is referring to using a hodgepodge of tools to implement a custom backup solution, especially referring to writing a backup tool in a shell script or a batch file.  Backups must be clean and easy to understand and should endeavor to utilize a single, unified platform across all hardware in an organization or home to successfully backup data.

Cross-platform
--------------

Any backup solution worth using has native Windows, Mac, and Linux clients.  Other OSes are welcome to join the party too but the big three are absolute.  A consistent user experience across OSes is also essential.

Running a mixture of OSes throws a monkey wrench into deploying a uniform backup solution as very few backup solutions are truly cross-platform.

Command-line First
------------------

Servers generally don't have point-and-click interfaces and so GUI-based backup tools don't work there.  A GUI can always be built later.  A lot of commercial backup solutions have a GUI but no command-line support.

This requirement also enables rapid deployment across a whole network of machines.

On-site Backups
---------------

Good backup software supports on-site backups that utilize both attached and network storage for local casual data loss.  This requirement is met by most traditional backup software.

Off-site Cloud Storage
----------------------

Modern backup software must support off-site, cloud storage.  This requirement covers catastrophic on-site data loss (e.g. fire, flood, malware).

At least one choice for off-site cloud storage that is supported by the software must have a public RESTful API, be affordable, and offer unlimited data storage and transfer.

Standards-based Configuration Files
-----------------------------------

Configuration files should be in JSON or XML.  This requirement allows automated deployment tools to do their thing.

Incremental Backups
-------------------

Snapshots of your data in the past created on the schedule you defined.  This requirement excludes tools like `rsync` which simply synchronize data instead of performing backups.

No Deltas
---------

File-based deltas do not work for backup.  I've personally seen deltas of popular backup software products corrupt data time and time again, ruining the most important portions of the backups.  Only full file backups work.

Backup software must have the ability to turn off file deltas for incrementals.

Self-Backup
-----------

The host can back itself up using the software standalone.  There shouldn't be a need to set up a separate, complex server solution.  This requirement excludes popular enterprise-ready tools.

Backup Verification
-------------------

At a minimum, a tool that spot checks the baseline and each incremental of a backup should be part of the backup solution.

Block-based Compression and Encryption
--------------------------------------

This is a requirement for performing an efficient backup to online cloud storage services.  Encryption of the data being sent is an absolute must before sending data to a remote, untrusted host.

Once implemented, the same block-based file structures are also incredibly useful for performing both local and network backups.

Small, Reliable File Change Tracker
-----------------------------------

A self-contained, ACID-compliant database such as SQLite works well here as a bare minimum.

Tracks Platform-agnostic Metadata
---------------------------------

The backup software should preserve UNIX-style timestamps, permissions, group name, and username as best as possible.

ACLs and OS-specific information are much more complex and difficult to track and are therefore optional but recommended.

Symbolic Link Support
---------------------

Symbolic links, or symlinks, are non-files/directories that point at a destination.  Preserving them through a backup is essential for correct operation when restoring the file system structure later.

Hard links, sparse files, and other rare oddities on the other hand are optional as they tend to lose their meaning when restored later.

E-mail Notifications
--------------------

When a backup is complete, the backup software notifies appropriate parties about what changed since the last backup.  Filtering reduces unnecessary noise in the e-mails.  Doubles as an informal intrusion detection system and
regular confirmation that the backup is still working properly.

Lightweight
-----------

The backup solution itself should have a very small system footprint.  CPU, RAM, I/O usage, and storage requirements should never be apparent.  A lot of backup solutions don't meet this criteria.

Network Knowledge
-----------------

Backup solutions must attempt to mitigate various attack vectors when it sends data over a network, including at-rest data.  The software should also be able to handle monthly ISP transfer limits for backups that utilize Internet bandwidth.

Modern Defense Infrastructure
-----------------------------

Good backup software is always be written in a scripting language.  Scripting languages offer a natural defense against things such as buffer overflows.  Other features such as crypto malware/ransomware defenses are welcome additions.

Software written in scripting languages is also usually open source, which goes back to the very first point.

Easily Deployed
---------------

There should be simple, clear instructions for getting started.  Video walkthroughs are welcome additions.  Forums and other methods of help should be available to get product support should the need arise.
