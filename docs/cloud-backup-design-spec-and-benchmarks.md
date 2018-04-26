Cloud Backup Design Specification and Benchmarks
================================================

This is a technical document with details about how Cloud Backup works.  Before using a backup software solution, it is a good idea to be familiar with how it functions under the hood.  Backup systems are not perfect - some are better than others depending on the type(s) of data being backed up.

Cloud Backup sends data to remote, possibly untrusted hosts over the Internet (i.e. the cloud).  There are certain requirements that need to be met before sending data to such hosts such as encrypting it in advance.

Files
-----

Let's talk about files for a bit.  Backing up directory names and symbolic links are extremely minor bits of information.  They are important, sure, for maintaining structure, but they occupy little space and are relatively unimportant.  Files, on the other hand, are where data is stored.  That data is what is important to most people and is a reasonable expectation that a backup system will take good care of that data.

Files mostly come in two main types:  Plain text and binary.  Plain text files can be opened up in Notepad or another text editor.  However, text files are really just a special case of a binary file and, from a backup system design perspective, all files should be treated as binary, opaque data.

Files come in all sizes.  There are small files, big files, zero-byte files, and everything in-between.  A backup system should handle all sizes of files.  The most challenging file sizes are those over 2GB due to 32-bit limitations and...thousands of tiny files.

Transferring 1,000 files over to another computer across a network, especially using protocols like (S)FTP is pretty slow.  Transfering a single file that exceeds the total size of the 1,000 separate files over the same network completes in a fraction of the time.  This is a repeatable problem.  The issue is one of data coalescence.  This brings us back to Cloud Backup.  Suffice it to say, sending a zillion little tiny files to a cloud storage provider would take forever and cost many, many API calls.  Cloud Backup uses a block-based strategy to solve this and other problems with sending data over a network to a destination host.

Blocks
------

A block is a chunk of data.  In Cloud Backup, a block may contain one or more files.  Blocks may be broken up into parts for easier transmission and error handling over a network.

Cloud Backup has two types of blocks:  Shared and non-shared.  During a backup, the following logic is used:

* If the file size is under the small file limit after compressing it (1MB by default), the file is placed into the current shared block if there is space OR the current shared block is encrypted and uploaded and a new shared block is started.
* Otherwise, a new non-shared block is used and the file is compressed, encrypted and uploaded solo.

The Benchmarks section below shows the impact that the above rules have:  Approximately 275,000 fewer network requests are made!  Gathering the smaller files first on a host into larger files makes a dramatic difference.

Block Parts
-----------

For most blocks, they will have one part and counting starts at 0.  For example, '0_0.dat' is read as block 0, part 0.

Block numbers increment over time and correspond to a matching number in the database.

The default upper limit on the size of a block part in Cloud Backup is 10MB.  This limit exists for a number of reasons, but mostly to keep RAM and network usage down.  In order to decrypt a block part, it has to be loaded completely into RAM.  Due to how PHP works, there might be 2-3 copies of the block part at any given point in time when it is being read/written, which translates to about 30MB RAM.  Throw in not wanting to waste transfer limits with failed uploads and 10MB becomes a decent default limit.  The configuration file can be modified to change the limits if your backup needs are different but, generally-speaking, the default setting is a good enough starting point for most users.

Block File Naming
-----------------

Cloud Backup names files in a mostly opaque manner.  However, there are are few reserved blocks that are stored in the target in specific ways.  For example, '0_0.dat' is the compressed, _encrypted_ 'files.db' SQLite database file.

Beyond the first three blocks, determining what data is contained in a given block requires decrypting the block.  Without the decryption keys, the data and knowledge about the data is useless.

Encryption
----------

Cloud Backup uses two AES-256-CBC symmetric key and IV pairs to encrypt all data and uses the [standard CubicleSoft two-step encryption method](http://cubicspot.blogspot.com/2013/02/extending-block-size-of-any-symmetric.html) to extend the block size to a minimum of 1MB.

Anyone who wants to reverse-engineer the dual encryption keys has to repeatedly decrypt 1MB of data twice.  Even if AES is ever fully broken, your data is still probably safe and secure from prying eyes.  The data being encrypted is surrounded with random bytes so that even the same input data results in completely different output.  Each block part also includes the size of the data and a hash for verification purposes.  The data is also padded with random bytes out to the nearest 4096 byte boundary (4K increments).  All of this helps make it that much more difficult for an attacker to guess what a file might contain.

Since data is encrypted, the keys must be kept safe and a copy of the Cloud Backup configuration should be kept offline so that data can be recovered.

Benchmarks
----------

When it comes to moving large quantities of data, performance is important.  Keep in mind that benchmarks are merely demonstrative and that Cloud Backup performs both transparent compression and two rounds of encryption of the data being backed up. In PHP.

The following system was used for the benchmarks:

* Intel Core i7-6700K (6th Gen CPU)
* 32GB RAM - DDR4 2133MHz SDRAM
* Windows 10 Pro 64-bit
* Windows Security Essentials
* Internal 640GB 7200 RPM Western Digital Hard Drive, Caviar, Black, SATA II connection with ~240GB of data to back up (258,162,126,382 bytes)
* External 3TB Western Digital Hard Drive, Green, USB 3.0 connection to backup to

The worst-performing component in that mix is the external hard drive to which data was written as well as Windows Security Essentials checking every file that was opened up.  The measured write speed of the drive varied fairly wildly.  One moment it plugged along at 25MB/sec and the next it inexplicably plummeted to 5MB/sec.  It was a hard drive bought at a bargain basement price and it's primary purpose is longer-term storage rather than heavy-duty use.

An initial backup using the 'local' option during 'configure.php', resulted in the following useful stats:

* Cloud Backup took around 5.5 hours to complete.  Data moved through at an average rate of 13.04MB/sec.
* 240GB of data compressed to 161GB. A 32.8% reduction in the amount of data stored.
* 280,537 files were backed up across 39,534 directories.  Of those, 275,145 ended up in 427 shared blocks.

The second run performed 24 hours later, resulted in the following useful stats for the first incremental:

* The backup software took approximately 3 minutes to scan the entire system and create the incremental.
* 244MB of compressed data was stored in the incremental across 23 blocks.
* 30 new folders, 168 shared files, and 4 non-shared files were added.
* 45.5MB compressed (118MB uncompressed) of additional data was added to the total.  Several large files obviously changed between the two runs.

All-in-all, this is a very solid showing for a backup system written in PHP.  Obviously, the cloud service portions of this tool have much longer, slower times - obviously taking days to move the same amount of data over a network that might also have monthly data caps applied.
