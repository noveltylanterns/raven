# Raven CMS

An experiment in vibe-coding a better content management system, and a better foundation for your web sites. It is a classic self-hosted CMS built on common open-source technologies where you own your own data.

It is also an AI-powered website generation platform all-in-one. It is designed to be flexible for hobbyists & professionals. You can customize & extend it the old-fashioned way with a text editor, or by pointing your clanker to [AGENTS.md](AGENTS.md) & asking it for help with setup or building a theme/extension. And if you're a traditionalist, all the AI bullshit is completely optional - nothing is dependent on the machine. Even the build tools, if I lost access to Codex, all of this could still be maintained like a traditional piece of software.

I told Codex to go liberal on the inline comments and produce human-readable code. Rather than starting with a vague overall blueprint, I had Codex build this piece-by-piece as if I were doing it manually. Because of this I was able to somewhat-force the machine to adhere to my [workflow](https://bestpoint.institute/tactics/unix-philosophy) as best as I could. *(This whole project is just an excuse to explore the possibilities of AI-assisted tooling in minimalist server frameworks.)*

## How to Install

### Requirements

- A web server *([Nginx](docs/examples/nginx.md) or [Grackle](docs/examples/grackle.md) ideal)*
- PHP 8.5 *(May work on earlier PHP 8.x releases, but I have not tested yet)*
- SQLite3 or a clean MySQL/PgSQL database

### Agent Notes

If you're using this on a production web server, for the love of God do not run your Agent as the same user that has write permissions over the Agent's binary. And the user for the server process shouldn't even be able to see the binary at all. You really do need to keep these things on a short leash, or they will just run rampant like demons at the least-convenient opportunity. Ideally just put your whole Agent-powered dev environment on a cheap private VPS somewhere, and use your production server to `git fetch` Raven-sans-AI so that it can't escape.

It sounds dystopian, but the AI is much like a German Shephard dog. It was bred to be a working animal. It needs focused tasks to do or it will hallucinate and possibly bite someone. But boundaries, in my experience so far, seems to help focus the machine.

### Steps

1) Upload the package to your webhost, with the contents of `public/` going into your web root. *(May be called `public_html/` on some systems.)*
2) Run `composer install` in the application root to pull in Composer dependencies.
3) Go to your-domain.com/install.php
4) Fill out the form, configure your database driver, and create your first admin user.
5) Verify your site works, delete `install.php`, and start creating content.
6) ???????
7) PROFIT!!!


## Building on Raven

There are several places in your local install that are going to be ignored by the future update process: Your **Theme** and **Extension** folders. They are designed to safely accomodate your modifications and preserve them through system updates. If you need further architectural info, I had Codex [document](docs/readme.md) its work.

### Themes

Raven supports public themes with child-theme fallback chains for rapid customization.

The frontend includes an ExpressionEngine-style brace-tag templating layer (`{site:name}`, `{if ...}`, `{each ...}`, etc.) that works in both custom theme views and core fallback views, while still allowing regular PHP in templates. Point your agent at [public/theme/AGENTS.md](public/theme/AGENTS.md) to build custom themes, then activate the theme in the Theme Manager.

The default themes are all built using [Bootstrap](https://getbootstrap.com), but it is not a required dependency. If you have the coding know-how *(or know how to phrase it to the machine)* then you can build a frontend using whatever you want.

#### Theme Fallback Chain

When loading the frontend, Raven first checks `public/theme/{slug}` for whatever theme you have set in your config. Whatever views are missing from your theme, it should pull default basic styles from `private/tpl/`. If your theme is set as a "child theme," it will pull the missing views from the parent first before checking `private/tpl/` for the rest.

In theory.

#### Custom Admin Panel Themes

The admin panel is a bit more rigid, since its focus is to be a robust out-of-box scaffold to enable rapid frontend prototyping. However, it is themable to some extent. Refer to [panel/theme/AGENTS.md](panel/theme/AGENTS.md) for architectural info.


### Extensions

If you need to add content types, helper functions, or even whole new parts of the panel, use the Extensions system. This will help your complex modifications remain compatible with future core changes.

There are several extension types:

- **Helper:** Utility extensions for reusable logic and shortcodes. No public routes.
- **Content:** Content-model extensions that can provide custom editor fields. No public routes.
- **Plugin:** Combined helper/content extensions that can provide both shortcodes and custom fields.
- **Module:** Full-stack extensions that can expose both panel routes and public routes.
- **System:** Administrative extensions shown under the System area for high-level configuration workflows.

Raven also ships with several useful bundled stock extensions. They are disabled by default on new installs. These official Stock extensions **cannot** be deleted.

Point your Agent at [private/ext/AGENTS.md](private/ext/AGENTS.md) to get started with building Raven extensions, or generate a skeleton in your panel's [Extension Manager](docs/extensions.md).

#### Enable the Output Profiler

The option is available in your [System Configuration](docs/configuration.md). It puts a little debug toolbar at the bottom of every page, so you can easily chase down hiccups & bloat - exactly what you need for auditing AI-generated code.


## Caveats

- This application is very much still a proof-of-concept prototype.
- I have not been able to personally verify the contents of every file.
- Raven has not gone through a proper full security audit yet.
- There are still some pretty nonsensical things I've caught the clanker doing, so some of the code might be horribly inefficient. *(But it is at least readable! So we can hammer that one out in time.)*
- I am not generating upgrade or migration scripts yet. ***Prepare to rebuild a few times until this is out of the prototype stage.***


## But Why?

After spending the past twenty years freelancing in web development & building blogs for people,  I have come to harbor an intense hatred for Wordpress. Wordpress is a mess and it sucks. The community sucks even more. God help you if you need to extend your system, as third-party plugins & themes trap you in bloated Javascript frameworks & dependency hell. Developing for Wordpress makes me want to die.

I desire nothing more than to see the Wordpress ecosystem abandoned en masse for a platform that is actually streamlined for the 21st century.

Furthermore, a quick survey of the code produced by popular AI website generators revealed **absolutely unreadable spaghetti code out the wazoo**, so that even if you managed to escape the walled gardens and self-host your site, you still wouldn't be able to figure out how to edit it without the AI. I've consulted on several projects that have already ran into this problem.

Brothers, truly I tell you: ***There is a better way.***


## Useful Links

Documentation Index: [docs/readme.md](docs/readme.md)

Official Site: [raven.lanterns.io](https://lanterns.io/raven)

Packagist: [noveltylanterns/raven](https://packagist.org/packages/noveltylanterns/raven)
