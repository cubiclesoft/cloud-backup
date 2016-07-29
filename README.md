Cloud Backup
============

A flexible, powerful, and easy to use rolling incremental backup system that pushes collated, compressed, and encrypted data to online cloud storage services, local attached storage, and network storage.

Features
--------

* All the [standard things you need in a backup system](http://barebonescms.com/documentation/cloud_backup/).
* Transparent compression and encryption before sending data to the storage provider.
* Supports these cloud storage services:  [Amazon Cloud Drive](https://www.amazon.com/clouddrive), [OpenDrive](https://www.opendrive.com/), and [Cloud Storage Server](http://barebonescms.com/documentation/cloud_storage_server/).
* Supports local attached storage.
* Block-based storage for major reductions in the number of API calls made.
* And much, much more.  See the official documentation for a more complete feature list.
* Also has a liberal open source license.  MIT or LGPL, your choice.
* Designed for relatively painless integration into your environment.
* Sits on GitHub for all of that pull request and issue tracker goodness to easily submit changes and ideas respectively.

More Information
----------------

Documentation, examples, and official downloads of this project sit on the Barebones CMS website:

http://barebonescms.com/documentation/cloud_backup/

Adding Cloud Services
---------------------

To be included in Cloud Backup, a cloud storage service must meet four criteria:

* A publicly available, well-documented, officially supported RESTful API.  Preferably one that is also complete, stable, and without daily request limits on the API itself.
* Be affordable.  There's no hard-and-fast requirement here other than the cost must be a fixed monthly rate NOT dependent upon how much data is stored.  A free option is nice for integration purposes but not required.
* Unlimited data storage.  Read their EULA for the fine-print.
* Unlimited data transfer.  Unlimited transfer is not the same as unlimited bandwidth.  Speed doesn't matter as long as all of the data to back up eventually gets backed up.

Once a cloud storage service meets the minimum criteria, two things have to be built:  A general-purpose PHP SDK (a PHP class) for the cloud service and then a specialized interface (another PHP class) that handles the communication between Cloud Backup and the service's PHP SDK.

And then, of course, it takes time to test the whole thing to make sure it all works properly.

Other Thoughts
--------------

I'd love to see CrashPlan and Backblaze develop open, public APIs for their services.  Currently, only the Amazon Cloud Drive and OpenDrive services meet the minimum criteria.

[Cloud Storage Server](http://barebonescms.com/documentation/cloud_storage_server/) is a self-hosted cloud storage API that was developed to create a really nice baseline that plays nicely with the Cloud Backup software.

Finally, OpenDrive is a bit weird in that the service is only occasionally mentioned on the Internet, rarely reviewed, and can even be hard to find, but happens to have the only complete, published API of a hosted storage service (quite rare), the API has no rate limits (rare), and a non-restrictive EULA (extremely rare).  They do have some API connectivity/stability/uptime issues that I wish they would work out but I just find it very bizarre that they aren't mentioned/reviewed more frequently - maybe it is the $13/month that drives reviewers away.  However, OpenDrive is an excellent choice for businesses that want to securely back up their data off-site via Cloud Backup - mostly because of that EULA.  OpenDrive was the first service to be included in Cloud Backup.  It took a long time to find them too because Google Search kept burying OpenDrive results, but software developer and small business friendly companies like theirs quickly earn my respect.  I wasn't paid to say that.  I'm a small business owner myself, so I know how hard it can be to get much-needed attention.
