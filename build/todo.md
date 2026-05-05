# Raven CMS Running To-Do Checklist

This document tracks current/future bugs, patches, modifications & feature additions for the Raven CMS platform.
This is the default Build Mode backlog file. If the user asks about goals, unpatched bugs, roadmap goals or what to build next, check this file before searching elsewhere in the repo.

## REQUIRED AGENT PROCEDURE
- Every task completed in this file gets noted in `release-notes.md`
- After completing a batch of tasks, make sure relevant documentation is up-to-date.
- Periodically prune checked items off of this list, since `release-notes.md` logs them.
- For every legacy fallback/migration path, function, variable & alias you create, note it in "Legacy Fallback Log" at bottom of this page, since we will be pruning them in future maintenance runs.
- Update this file as you go (add sub-checklists as need be) to keep track of your progress, in case the session breaks and we have to start over.
- `build/long.md` houses long-term project & roadmap goals, for optional secondary context. Do not load it on short-term build tasks.



# Postmaster Service

**Context:** Two places currently send mail independently — `lib/Auth/LoginEmail.php::sendCode()` (2FA codes, uses `mail()` only) and `ext/contact/lib/ContactPublicFormRuntime.php::sendContactMail()` (form submissions, tries sendmail binary first then falls back to `mail()`). Both duplicate: address normalization, domain extraction for headers, no-reply fallback generation, header sanitization, and Message-ID generation. Postmaster lifts the working contact-ext sendmail+fallback delivery into a shared service; lib/Mail/ houses the reusable primitives both it and extensions need.

### Phase 1 — lib/Mail/ primitives (new files)
- [x] `lib/Mail/Address.php` — static utility class: `normalize(string): ?string`, `mask(string): string`, `headerDomain(string): string`, `defaultNoReply(string): string`, `sanitizeHeader(string, int): string`
- [x] `lib/Mail/Message.php` — immutable value object: `to`, `cc`, `bcc`, `replyTo`, `subject`, `body`, `customHeaders`; fluent builder: `withReplyTo`, `withCc`, `withBcc`, `withHeader`

### Phase 2 — sys/Postmaster.php (new file)
- [x] `__construct(Config $config)` — reads `mail.agent`, `mail.sender_address`, `mail.sender_name`, `site.domain`
- [x] `send(Message $message): array{ok, message?}` — tries sendmail binary first (lifted from contact ext), falls back to `mail()`
- [x] `senderAddress(): string` and `senderName(): string` — expose configured sender metadata
- [x] Private: `sendmailBinary(): ?string`, `viaSendmail(...)`, `buildBaseHeaders(...)`, `buildMessageId(string): string`

### Phase 3 — Wire Postmaster into container
- [x] Add `'postmaster' => new Postmaster($config)` to `$rvn` in `private/Raven.php` (after Config is ready, before extension boot)

### Phase 4 — Refactor lib/Auth/LoginEmail.php
- [x] `sendCode()` signature: drop `$siteDomain`, `$senderAddress`, `$senderName`, `$mailAgent` params; add `Postmaster $postmaster`; use `Address::` helpers and build a `Message`, call `$postmaster->send()`
- [x] `maskEmail()` → thin wrapper around `Address::mask()`
- [x] Remove private helpers now owned by Address/Postmaster: `sanitizeText`, `defaultNoReplyAddress`, `mailHeaderDomain`

### Phase 5 — Refactor lib/Auth/LoginChallenge.php
- [x] Add `Postmaster $postmaster` to constructor; remove mail config reads from `sendCode()` call site

### Phase 6 — Update LoginChallenge instantiation sites (2 files)
- [x] `sys/Controller/Public/AuthController.php::loginChallengeWorkflow()` — pass `new Postmaster($this->context->config())`
- [x] `sys/Controller/Panel/AuthController.php::loginChallengeWorkflow()` — pass `new Postmaster($this->config)`

### Phase 7 — Refactor ext/contact/lib/ContactPublicFormRuntime.php
- [x] Add `Postmaster $postmaster` to constructor
- [x] `sendContactMail()` — build a `Message`, call `$this->postmaster->send()`; remove thrown RuntimeException style (return error instead, or keep throw — decide at implementation time)
- [x] Remove private helpers now owned by Postmaster/Address: `sendContactMailViaSendmail`, `sendmailBinaryPath`, `configuredMailSenderAddress`, `configuredMailSenderName`, `mailHeaderDomain`, `defaultNoReplyEmail`

### Phase 8 — Update ext/contact/ext.php
- [x] Pass `$rvn['postmaster']` when constructing `ContactPublicFormRuntime`

### Phase 9 — PHPDoc sweep & release notes
- [x] PHPDoc all new and changed methods (lib/Mail/*, sys/Postmaster.php, LoginEmail, LoginChallenge)
- [x] Append to release-notes.md



# lib/Auth/ Refactor
Lingering issues & reorganization tasks. Make a plan to deal with them all in one clean sweep. Append it as a detailed checklist to this section in case we lose session or we have to bounce between agents:
- [ ] All root Auth/ classes (EXCLUDE Auth/Panel/*.php & Auth/Public/*.php classes) should be public/panel-agnostic primitives. Doublecheck them all to make sure that is functionally the case.
- [ ] Why do we have Mask.php & PermissionMaskService.php for both our routes?
	- I thought each route was supposed to have a single Mask.php handling permission masks for that route.
	- I see one leads more towards base permissions constants for its respective route, and the other is a dynamically-computed usermask while on that route. Is this assessment correct?
	- If these are too much to flatten, rename Mask.php to PermissionBase.php, and rename PermissionMaskService.php to PermissionMask.php.
- [ ] Panel/PermissionDefinitionCatalog.php:
	- This is more of a form builder than a real Auth/ class, if I am not mistaken.
	- Probably best moved to lib/View/Panel/EditorPermissions.php
- [ ] Panel/RolePolicy.php:
	- Where is this used? Because we have Groups, not Roles.
	- Roles were never fully implemented and became Groups.
	- Some functions look like they belong inside one of the User/Group parser classes, as they deal with primitive route & slug parsing.
	- Some functions look like they belong merged/flattened into either Panel/PermissionBase.php, or Panel/PermissionMask.php, or a mix of both.
	- Furthermore, a lot of these functions are wrappers that don't add anything of value. Purge them.
- [ ] AuthPayloadCodec.php: Unclear name & purpose. Need details to sort it.
- [ ] Rename LoginThrottle.php to ThrottleUser.php
- [ ] AuthService.php: 1400+ line monstrosity.
	- Seems to be a dump of several barely-related groups of Auth functions.
	- AuthService.php should be the bare minimum needed for login+authentication flows.
	- Start cleanup of this file by purging all legacy aliases, compatability shims, and thin function wrappers that don't add extra logic. Update all callers to use actual source functions.
	- Panel-specific functions should be extracted to Auth/Panel/Service.php
	- Public-specific functions should be extracted to Auth/Public/Service.php
	- Function verifyPendingRecoveryCode should be extracted to lib/Security/PhraseValidate.php
	- Function verifyPendingEmailCode should be extracted to lib/Security/EmailValidate.php
	- Functions verifyTotpCode & verifyPendingTotpCode should be extracted to lib/Security/TotpVerify.php
	- Functions for reading/returning throttle buckets & failed logins should be extracted to ThrottleReturn.php
	- Functions for writing/editing throttle buckets & failed login attempts should be extracted to ThrottleReturn.php
	- Functions for deleting/emptying throttle buckets & failed login attempts should be extracted to ThrottleClear.php
	- Functions for building User preferences forms should be extracted to View/Preferences.php, and made public/panel/extension-agnostic. Update callers to use new class directly.
	- Function maskEmail should be extracted to new class Security/EmailObfuscate.php
- [ ] LoginEmail.php should be condensed & simplified, with many of its primitive functions extracted out to Security/EmailValidate.php and Security/EmailGenerate.php 
### lib/Auth/ Cleanup
- [ ] Make sure no Auth class is pulling up dead function/class/dependency weight irrelevant to the auth method/helper that class handles.
- [ ] At this point, rescan all of lib/Auth/, and look for redundant functions that can be merged/flattened. Optimize the hell out of this whole folder. After that, re-scan all Auth/ classes for dead dependency weight again before moving on.
- [ ] Scan the whole Auth/ directory for legacy aliases, compatability shims, and thin wrappers that don't add any extra logic. Purge all of them. Update all callers to use actual source functions.
- [ ] A lot of the functions in our Auth/ classes have really long & unclear names. Do a sweep of every class and make sure the function/variable names are concise+accurate.
- [ ] Do a sweep of all classes in Auth/ making sure PHPdoc blocks are present+accurate for ALL headings, classes & functions.
- [ ] Update release-notes.md, clear completed section out of todo.md, and commit.



# lib/Database/ Refactor
Lingering issues & reorganization tasks. Make a plan to deal with them all in one clean sweep. Append it as a detailed checklist to this section in case we lose session or we have to bounce between agents:
- [ ] I'm really not terribly sure what's happening in this folder:
	- Near as I can tell, sys/Runtime/DatabaseFactory.php is our 'core' database connection service, and everything in lib/Database/ is a primitive to support database operations? Or are there other core components in lib/Database/ that should move back to sys/Runtime/?
	- We'll have to go through these classes one by one when making our plan.
	- Explain what each class here does, and what routes/functions/controllers/etc call them.
	- I'll call where to put each class, what to name it, and what (if any) functions to extract towards different classes.
	- Some of these classes have redundant-looking functions. I also see what looks like helper wrappers that don't add any real extra logic. Identify & note all of them.
	- Purge all pointless helper wrappers, and update callers to use source functions directly.
	- If you have more ideas how to consolidate our Database into something more sensible+efficient, let me know and I may consider.
- [ ] All Database/ classes should be public/panel/extension-agnostic primitives. Doublecheck them all to make sure that is functionally the case.
### lib/Database/ Cleanup
- [ ] Make sure no Database class is pulling up dead function/class/dependency weight irrelevant to the database query that class handles.
- [ ] Scan the whole Database/ directory for legacy aliases, compatability shims, and thin wrappers that don't add any extra logic. Purge all of them. Update all callers to use actual source functions.
- [ ] A lot of classes+functions in Database/ have really long & unclear names. Do a sweep of everything in Database/ and make sure all the class/function/variable names are concise+accurate.
- [ ] Do a sweep of all classes in Database/ making sure PHPdoc blocks are present+accurate for ALL headings, classes & functions.
- [ ] Update release-notes.md, clear completed section out of todo.md, and commit.



# lib/Media/ Refactor
Lingering issues & reorganization tasks. Make a plan to deal with them all in one clean sweep. Append it as a detailed checklist to this section in case we lose session or we have to bounce between agents:

### Prep Work
- [ ] There should not be a lib/Media/Panel/ folder:
	- [ ] lib/Media/ should consist entirely of public/panel/extension-agnostic primitives.
	- [ ] Move everything in lib/Media/Panel/ to lib/Media/ and delete the empty folder.
- [ ] Scan the whole lib/Media/ directory for legacy shims and thin wrappers that don't add any extra logic. Purge all of them. Update all callers to use actual source functions.
- [ ] Move PageEditorGalleryHydrator.php to lib/View/Panel/EditorMedia.php, update callers.
- [ ] Do a sweep on our newly consolidated lib/Media/ folder to make sure that, in practice & function, all of our lib/Media/ classes are truly public/panel/extension-agnostic.

### Refactor Avatar Libraries
- [ ] AvatarUploadService, AvatarValidationPolicy, and AvatarValidator, can all be merged into two new condensed classes: AvatarUpload.php and AvatarValidator.php. Distribute the avatar functions from the three original classes within the two new ones however makes the most sense (shoot for processing efficiency). Update all callers of the original three classes to use the two new ones.
- [ ] Does our new AvatarUpload.php use Transport/Upload.php? Should it for consistency?
- [ ] Split up MediaConfigService.php:
	- [ ] Extract all avatar-related functions and place them in new Media/AvatarConfig.php class
	- [ ] Remaining functions go in new focused Media/MediaConfig.php class.
	- [ ] Update all MediaConfigService callers to use AvatarConfig & MediaConfig (only use the one(s) that caller actually needs, DO NOT indiscriminately bundle both everywhere)
	- [ ] Delete MediaConfigService.

### Refactor Image Processors
- [ ] ImageVariantProcessor.php:
	- [ ] Extract all Exif-related functions to new ImageExifProcessor.php class.
	- [ ] Update all callers to new source path. 
	- [ ] Remaining functions in theory should just be related to variant processing.

### Refactor Media Management Classes
- [ ] Decommision MediaManager.php:
	- [ ] Does MediaManager.php use Transport/Upload.php? Should it for consistency?
	- [ ] All Imagemagick-related functions should be extracted and moved into Media/ImageImagickProcessor.php
	- [ ] Remaining MediaManager functions in theory should all be upload logic, so put them in Media/MediaUpload.php.
	- [ ] MediaUploadPolicy looks like something that could be folded into MediaUpload, unless you see a good reason not to.
	- [ ] Update callers, and delete leftover MediaManager (and potentially MediaUploadPolicy).
- [ ] MediaPathLayout.php:
	- removePageDirectoryIfEmpty looks like something that should use the lib/Archive/Folder.php primitive, and renamed to simply removePageDirectory.
	- ensurePageDirectory looks like something that should use the lib/Archive/Folder.php primitive.
	- rename MediaPathLayout to MediaStorage.php, update callers to use new source.

### Break Up Shared Taxonomy/User Image Processors
- [ ] Refactor TaxonomyImageService.php & TaxonomyImagePathResolver:
	- [ ] This shared Taxonomy* set is a monolith, but essentially just cover & preview images.
	- [ ] Lets make them generic `Media/Cover*.php` & `Media/Preview*.php` classes, so they can eventually be used for more than just taxonomy routes:
		- CoverConfig.php
		- CoverUpload.php
		- CoverValidator.php
		- PreviewConfig.php
		- PreviewUpload.php
		- PreviewValidator.php
	- [ ] Update callers and delete Media/Taxonomy*.php classes
	- [ ] Anything still missing from above files after refactor will have to be clone from Avatar classes.
- [ ] Split & decommission UserMediaPathService.php:
	- [ ] Extract & migrate avatarTemplateData to AvatarConfig.php
	- [ ] Extract & migrate coverPublicUrl to CoverConfig.php
	- [ ] Extract & migrate thumbnailFilename to.... whatever Avatar*.php class is relevant.
	- [ ] Update all callers and delete UserMediaPathService.php

### Final Cleanup
- [ ] We have some scattered EXIF-processing logic throughout lib/Media/. Extract it all from whereever it is, compile into new dedicated Media/ImageExifProcessor.php, update callers as necessary (be careful, it won't be all of them).
- [ ] Re-scan the whole lib/Media/ directory for legacy shims and thin wrappers that don't add any extra logic. Purge all of them. Update all callers to use actual source functions.
- [ ] A lot of the functions in our Media/ classes have really long & unclear names. Do a sweep of every class and make sure the function/variable names are concise+accurate.
- [ ] Do a sweep of all classes in Media/ making sure PHPdoc blocks are present+accurate for ALL headings, classes & functions.
- [ ] Update release-notes.md, clear completed section out of todo.md, and commit.



# lib/Security/ Refactor
Lingering issues & reorganization tasks. Make a plan to deal with them all in one clean sweep. Append it as a detailed checklist to this section in case we lose session or we have to bounce between agents:
- [ ] Captcha.php says its a public route class. It should be a public/panel/extension agnostic class.
- [ ] Totp.php & TotpCipher.php look partially agnostic, but it is hard to tell with inaccurate/missing PHPdoc blocks. Doublecheck to be safe.
- [ ] The other lib/Security/ classes already look public/panel/extension agnostic, but doublecheck to be safe as some PHPdoc blocks are dated fragments. Others are missing entirely.
- [ ] In PasswordValidator.php, function validateNewPasswordChange should be validateNewPass, $newPassword should be $newPass and $confirmNewPassword should be $confirmNewPass
- [ ] Security/RecoveryPhrase.php should be split into PhraseGenerate & PhraseValidate classes (use best judgement to sort functions into new classes. update callers and delete initial RecoveryPhrase class.)
### lib/Security/ Cleanup
- [ ] Make sure no Security class is pulling up dead function/class/dependency weight irrelevant to the method that class handles.
- [ ] Scan the whole Security/ directory for legacy aliases, compatability shims, and thin wrappers that don't add any extra logic. Purge all of them. Update all callers to use actual source functions.
- [ ] A lot of the functions in our Security/ classes have really long & unclear names. Do a sweep of every class and make sure the function/variable names are concise+accurate.
- [ ] Do a sweep of all classes in lib/Security/ making sure PHPdoc blocks are present+accurate for ALL headings, classes & functions.
- [ ] Update release-notes.md, clear completed section out of todo.md, and commit.



# lib/Transport/ Refactor
Lingering issues & reorganization tasks. Make a plan to deal with them all in one clean sweep. Append it as a detailed checklist to this section in case we lose session or we have to bounce between agents:
- [ ] Redirect.php has a function isAllowedHttpOrRootPath which is beyond the scope of this class. It should be extracted out of Redirect.php and merged into lib/Parser/RedirectParser.php
- [ ] Request.php has a lot of functions in it that feel outside the scope of basic Request primitives:
	- Most are missing PHPdoc blocks, obscuring the problem.
	- Many have really long function/variable names that should be assessed for length & accuracy.
	- resolveClientHostname & normalizeClientIp (and anything that records stuff like that about visitors) should be centralized in sys/Debug/ClientProfiler.php.
	- Other functions look like basic config parsing & URL assembly that arent quite just request primitives, but I do not know where to put them.
- [ ] While you're in sys/Debug/, a new corresponding Debug/LocalProfiler.php (for getting debug/environment information of the localhost Raven is installed on) would be useful to have too.
- [ ] It should go without saying but all lib/Transport/ classes should be public/panel/extension-agnostic primitives.
### lib/Transport/ Cleanup
- [ ] Make sure no Transport class is pulling up dead function/class/dependency weight irrelevant to the transport type that class handles.
- [ ] Scan the whole Transport/ directory for legacy aliases, compatability shims, and thin wrappers that don't add any extra logic. Purge all of them. Update all callers to use actual source functions.
- [ ] Some of the functions in our Transport/ classes have really long & unclear names. Do a sweep of every class and make sure the function/variable names are concise+accurate.
- [ ] Do a sweep of all classes in lib/Transport/ making sure PHPdoc blocks are present+accurate for ALL headings, classes & functions.
- [ ] Update release-notes.md, clear completed section out of todo.md, and commit.



# lib/View/ Refactor
Lingering issues & reorganization tasks. Make a plan to deal with them all in one clean sweep. Append it as a detailed checklist to this section in case we lose session or we have to bounce between agents:
- [ ] Error.php has a few actual error functions, and the rest of it is theme parsing & other unrelated functions. Anything not related to actually serving errors needs to be moved to more appropriate lib/ classes.
- [ ] Error.php has a lot of route-specific functions. This is Wrong. Error.php is supposed to be a generic panel/public/extension-agnostic primitive. Anything specific to routes should be handled in their respective controllers, routers & parsers. Alternatively, we can make dedicated focused View/Panel/Error.php & View/Public/Error.php files, and purge View/Error.php entirely
- [ ] Theme.php looks like it has wrapper functions that dont do anything extra. These should be purged and all callers updated to the true source. The rest of lib/View/ should be purged of similar wrapper/aliases like this.
- [ ] Theme.php's inline comments says it is a public theme helper class. I don't know if that is still true, or if this was made public/panel agnostic. It looks public-only, so it should probably be moved to View/Public/ThemeDiscovery.php, or merged/flattened into View/Public/ThemeCatalog.php entirely if that sounds more appropriate.
- [ ] There are no View/Panel/Theme*.php classes, which makes sense because it uses a simpler template engine. I don't know if they're hiding in unrelated classes (like the Error.php issue) or if they just don't exist. We should probably have, at a minimum, a single View/Panel/Theme.php file for the bare theme psuedo-catalog switch & primitives.
### lib/View/ Cleanup
- [ ] Make sure no View class is pulling up dead function/class/dependency weight irrelevant to the UI element that class handles.
- [ ] Scan the whole View/ directory for legacy aliases, compatability shims, and thin wrappers that don't add any extra logic. Purge all of them. Update all callers to use actual source functions.
- [ ] A lot of the functions in our View/ classes have really long & unclear names. Do a sweep of every class and make sure the function/variable names are concise+accurate.
- [ ] Do a sweep of all classes in lib/View/ making sure PHPdoc blocks are present+accurate for ALL headings, classes & functions.
- [ ] Update release-notes.md, clear completed section out of todo.md, and commit.




# Misc Bugs & Tweaks
**Do not delete this heading!**
- [ ] Since making recent progress on the library refactor, Raven's average response times have shot up from 15-40ms, to 50-180ms. Memory consumption & database queries seems to have increased a bit too. Lets figure out what's crosswired wrong to be calling all this new dead weight. Make a checklist plan for solving this, and a second checklist for a follow-up optimization pass.




# Data Access Layer Refactor Cleanup (Pending Plan, DO NOT PROCEED)

## lib/Parser/ Refactor
Lingering issues & reorganization tasks. Make a plan to deal with them all in one clean sweep. Append it as a detailed checklist to this section in case we lose session or we have to bounce between agents:
- [ ] Parser/ classes are designated data access points for novice Extension authors who have no reason to use Repositories directly.
- [ ] Parser/ classes SHOULD NOT be the primitives for Repositories. Repositories are the primitives for Parser/ classes. (Exception for *RepoParser.php classes, so Repositories have a designated safe zone for bare essential read primitives that would also be useful to give to Extension authors.)
### lib/Parser/ Cleanup
- [ ] Make sure no Parser is pulling up dead function/class/dependency weight irrelevant to the data type that Parser handles.
- [ ] Scan the whole Parser/ directory for legacy aliases, compatability shims, and thin wrappers that don't add any extra logic. Purge all of them. Update all callers to use actual source functions.
- [ ] A lot of the functions in our Parser/ classes have really long & unclear names. Do a sweep of every class and make sure the function/variable names are concise+accurate.
- [ ] Do a sweep of all classes in Parser/ making sure PHPdoc blocks are present+accurate for ALL headings, classes & functions.
- [ ] Update release-notes.md, clear completed section out of todo.md, and commit.

## lib/Scribe/ Refactor
Lingering issues & reorganization tasks. Make a plan to deal with them all in one clean sweep. Append it as a detailed checklist to this section in case we lose session or we have to bounce between agents:
- [ ] Scribe/ classes are designated easy entry points for novice Extension authors who have no reason to use Repositories directly.
- [ ] Scribe/ classes SHOULD NOT be the primitives for Repositories. Repositories are the primitives for Scribe/ classes. (The last agent had trouble finishing this as you can see, so clarify anything uncertain.)
### lib/Scribe/ Cleanup
- [ ] Make sure no Scribe is pulling up dead function/class/dependency weight irrelevant to the data type that Script handles.
- [ ] Scan the whole Scribe/ directory for legacy aliases, compatability shims, and thin wrappers that don't add any extra logic. Purge all of them. Update all callers to use actual source functions.
- [ ] A lot of the functions in our Scribe/ classes have really long & unclear names. Do a sweep of every class and make sure the function/variable names are concise+accurate.
- [ ] Do a sweep of all classes in Scribe/ making sure PHPdoc blocks are present+accurate for ALL headings, classes & functions.
- [ ] Update release-notes.md, clear completed section out of todo.md, and commit.

## sys/Repository/ Refactor
Lingering issues & reorganization tasks. Make a plan to deal with them all in one clean sweep. Append it as a detailed checklist to this section in case we lose session or we have to bounce between agents:
- [ ] All Repository/ classes should be public/panel/extension/library/scribe/cli-agnostic primitives. Doublecheck them all to make sure that is functionally the case:
	- Repositories are the canonical base data access+manipulation layer.
	- Parser/Scribe classes, Controllers & the CLI all access data through Repositories.
	- Repositories DO NOT access data through Parser/Scribe classes, as the Repositories ARE the primitives FOR those classes.
	- The only Parser primitives Repositories are allowed to call directly is our focused *RepoParser.php classes.
### sys/Repository/ Cleanup
- [ ] Make sure no Repository is pulling up dead function/class/dependency weight irrelevant to the data type that Repository handles.
- [ ] Scan the whole Repository/ directory for legacy aliases, compatability shims, and thin wrappers that don't add any extra logic. Purge all of them. Update all callers to use actual source functions.
- [ ] A lot of the functions in our Repository/ classes have really long & unclear names. Do a sweep of every class and make sure the function/variable names are concise+accurate.
- [ ] Do a sweep of all classes in Repository/ making sure PHPdoc blocks are present+accurate for ALL headings, classes & functions.
- [ ] Update release-notes.md, clear completed section out of todo.md, and commit.


# Future Refactor Cleanups (Pending Plans, DO NOT PROCEED)

## sys/Controller/ Refactor
Lingering issues & reorganization tasks. Make a plan to deal with them all in one clean sweep. Append it as a detailed checklist to this section in case we lose session or we have to bounce between agents:
- [ ] Doublecheck that all Controllers use Repositories directly, instead of Parser/Scribe classes (as much as reasonably possible).
- [ ] Clean up SharedController pair:
	- 
### sys/Controller/ Cleanup
- [ ] Make sure no Controller is pulling up dead function/class/dependency weight irrelevant to the route being loaded.
- [ ] Scan the whole Controller/ directory for legacy aliases, compatability shims, and thin wrappers that don't add any extra logic. Purge all of them. Update all callers to use actual source functions.
- [ ] A lot of the functions in our Controller/ classes have really long & unclear names. Do a sweep of every class and make sure the function/variable names are concise+accurate.
- [ ] Do a sweep of all classes in Controller/ making sure PHPdoc blocks are present+accurate for ALL headings, classes & functions.
- [ ] Update release-notes.md, clear completed section out of todo.md, and commit.

## sys/Debug/ Refactor Cleanup
Lingering issues & reorganization tasks. Make a plan to deal with them all in one clean sweep. Append it as a detailed checklist to this section in case we lose session or we have to bounce between agents:
- [ ] Make sure no Debug/ class is pulling up dead function/class/dependency weight irrelevant to the class/method being loaded.
- [ ] Scan the whole Debug/ directory for legacy aliases, compatability shims, and thin wrappers that don't add any extra logic. Purge all of them. Update all callers to use actual source functions.
- [ ] A lot of the functions in our Debug/ classes have really long & unclear names. Do a sweep of every class and make sure the function/variable names are concise+accurate.
- [ ] Do a sweep of all classes in Debug/ making sure PHPdoc blocks are present+accurate for ALL headings, classes & functions.
- [ ] Update release-notes.md, clear completed section out of todo.md, and commit.

## sys/Router/ Refactor
Lingering issues & reorganization tasks. Make a plan to deal with them all in one clean sweep. Append it as a detailed checklist to this section in case we lose session or we have to bounce between agents:
### sys/Router/ Cleanup
- [ ] Make sure no Router is pulling up dead function/class/dependency weight irrelevant to route being loaded.
- [ ] Scan the whole Router/ directory for legacy aliases, compatability shims, and thin wrappers that don't add any extra logic. Purge all of them. Update all callers to use actual source functions.
- [ ] A lot of the functions in our Router/ classes have really long & unclear names. Do a sweep of every class and make sure the function/variable names are concise+accurate.
- [ ] Do a sweep of all classes in Router/ making sure PHPdoc blocks are present+accurate for ALL headings, classes & functions.
- [ ] Update release-notes.md, clear completed section out of todo.md, and commit.

## sys/Runtime/ Refactor Cleanup
Lingering issues & reorganization tasks. Make a plan to deal with them all in one clean sweep. Append it as a detailed checklist to this section in case we lose session or we have to bounce between agents:
- [ ] Make sure no Runtime/ class is pulling up dead function/class/dependency weight irrelevant to the runtime being called.
- [ ] Scan the whole Runtime/ directory for legacy aliases, compatability shims, and thin wrappers that don't add any extra logic. Purge all of them. Update all callers to use actual source functions.
- [ ] A lot of the functions in our Runtime/ classes have really long & unclear names. Do a sweep of every class and make sure the function/variable names are concise+accurate.
- [ ] Do a sweep of all classes in Runtime/ making sure PHPdoc blocks are present+accurate for ALL headings, classes & functions.
- [ ] Update release-notes.md, clear completed section out of todo.md, and commit.

## Core & Library Refactor Cleanup
Lingering issues & reorganization tasks. Make a plan to deal with them all in one clean sweep. Append it as a detailed checklist to this section in case we lose session or we have to bounce between agents:
- [ ] Do a full sweep across every class in sys/ & lib/ to clear out legacy bloat:
	- [ ] Find & identify all legacy aliases & compatability shims.
	- [ ] Find & identify all functions that wrap other functions without adding extra logic.
	- [ ] Update all callers of these things to use source functions directly.
	- [ ] Purge all these legacy aliases & thin wrappers.
- [ ] A lot of our library classes have really long & unclear names. Do a sweep of every class in lib/ & sys/, and make sure the function/variable names are concise+accurate.
- [ ] Make sure all callers of these classes are using accurate variable names, as some may be legacy vernacular.
- [ ] Do a sweep of all classes in lib/ & sys/ making sure PHPdoc blocks are present+accurate for ALL headings, classes & functions.
- [ ] Finally, everything cleaned up & documented, do a full sweep over every class in lib/sys for redundant logic that can be merged/flattened (so long as it doesnt reintroduce dependency bloat or load dependencies from unrelated routes again), itemize them out in a checklist, clarify anything uncertain with me, and then run through this final optimization pass.
- [ ] Update release-notes.md, clear completed section out of todo.md, and commit.





# Legacy Fallback Log

Running ledger of backward-friendly and legacy shims added during the cleanup work, so they can be removed later once the new schema/contracts are fully settled.

Items below are the remaining classified legacy/compatibility lanes after the current purge pass.

---

- None currently logged.

---
