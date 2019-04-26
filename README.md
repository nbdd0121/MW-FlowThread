# MW-FlowThread
A commenting system for MediaWiki

## Dependency
* [Echo](https://www.mediawiki.org/wiki/Extension:Echo): This is required for FlowThread to work
* [Avatar](https://github.com/nbdd0121/MW-Avatar): This is optional, but it can provide avatar feature to FlowThread.

## Install
* Clone the respository, rename it to FlowThread and copy to extensions folder
* Add `wfLoadExtension('FlowThread');` to your LocalSettings.php
* Run the [update script](https://www.mediawiki.org/wiki/Manual:Update.php)
* You are done!

## Configuration
* All configurations are stored in `$wgFlowThreadConfig`
	* `$wgFlowThreadConfig['AnonymousAvatar']` (string), should be set to the URL of the avatar for non-registered user.
	* `$wgFlowThreadConfig['Avatar']` (string), should be set to the URL of the avatar for registered user. You can use ${username} as a placeholder for user's name. Please note that you need to use single quotes ('') to include the placeholder, otherwise PHP will substitute it right away.
	* `$wgFlowThreadConfig['MaxNestLevel']` (int): Default to 3, this restricted max level of nested reply.
* In old versions, we do not enforce POST method on APIs that require database write. This will be changed in the future. To allow migration, `$wgFlowThreadEnforcePost` is introduced to control this behaviour. It is current defaulted to false, but will later be changed to true.
* You can set user rights: 
	* comment: User need this right to post
	* commentadmin-restricted: User need this right to do basic management of comments
	* commentadmin: User need this right to do full management of comments

## Avatar Presentation
* FlowThread itself does not provide avatar feature, but it creates an extensible interface to allow other extensions/service to provide avatar for FlowThread.
* See the above section to know how to configure this extension.
* When the extension needs to show an avatar of a non-registered user, aka a IP user, it will use DefaultAvatarURL.
* When the extension needs to show an avatar of a registered user, it will use AvatarURL and replace all occurrence of ${username} to the user's actual username.
