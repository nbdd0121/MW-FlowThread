# MW-FlowThread
A commenting system for MediaWiki

## Dependency
* [Echo](https://www.mediawiki.org/wiki/Extension:Echo): This is required for FlowThread to work
* [ExtAvatar](https://github.com/nbdd0121/MW-Avatar): This is optional, but it can provide avatar feature to FlowThread.

## Install
* Clone the respository, rename it to FlowThread and copy to extensions folder
* Add `wfLoadExtension('FlowThread');` to your LocalSettings.php
* Run the [update script](https://www.mediawiki.org/wiki/Manual:Update.php)
* You are done!

## Configuration
* If you haven't installed ExtAvatar
* * $wgDefaultAvatar (string), should be set to the URL of the default avatar.
* $wgMaxNestLevel (int): Default to 3, this restricted max level of nested reply.
* You can set user rights: 
* * comment: User need this right to post
* * commentadmin-restricted: User need this right to do basic management of comments
* * commentadmin: User need this right to do full management of comments

