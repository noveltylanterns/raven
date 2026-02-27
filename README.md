# Raven CMS

An experiment in vibe-coding a better CMS. The idea is to package it with everything most people would need to build a website or blog, without having to install a million plugins.

It is both a classic self-hosted CMS & an AI-powered website generation platform all-in-one. It is designed to be flexible for hobbyists & professionals. You can build on it the old-fashioned way with a text-editor, or point your AI to the documentation & various bundled `AGENTS.md` files to extend your system with Codex, Claude, etc. And if you're a traditionalist, all the AI bullshit is completely optional - nothing is dependent on the clanker.

I told Codex to go liberal on the inline comments and produce human-readable code. Rather than starting with a vague overall blueprint, I had Codex build this piece-by-piece as if I were doing it manually. Because of this I was able to somewhat-force the machine to adhere to my [minimalist workflow](https://bestpoint.institute/tactics/unix-philosophy) as best as I could. *(A thing I am still figuring out myself, as this is learning experience on my part.)*

Loose guardrails are in place so your Agent can modify the system while remaining compatible with the rudimentary Git-powered update mechanism. *(Tho I say those guardrails are "loose" because I can't stop you from telling the machine to edit core system files or ignore AGENTS.md directives.)* The end result is a platform for a Wordpress-like software ecosystem where humans & clankers can code side-by-side in harmony, with the machines sensibly leashed up so they don't run amok.


## How to Install

### Requirements

- A web server *(Nginx or [Grackle](https://github.com/humphreyboagart/grackle) ideal)*
- PHP 8.5 *(May work on earlier PHP 8.x releases, but I have not tested yet)*
- SQLite3 or a clean MySQL/PgSQL database

### Agent Notes

If you're using this on a production web server, for the love of God do not run your Agent as the same user that has write permissions over the Agent's binary. And the user for the server process shouldn't even be able to read it at all. You really do need to keep these things on a short leash, or they will just run rampant like demons at the least-convenient opportunity. Ideally just put your whole Agent-powered dev environment on a cheap private VPS somewhere, and push Raven-sans-AI via Git to your live server from there.

### Steps

1) Upload the package to your webhost, with the contents of `public/` going into your web root. *(May be called `public_html/` on some systems.)*
2) Run `composer install` in the application root to pull in Composer dependencies.
3) Go to your-domain.com/install.php
4) Fill out the form, configure your database driver, and create your first admin user.
5) Verify your site works, delete `install.php`, and start creating content.
6) ???????
7) PROFIT!!!


## But Why?

After spending the past twenty years freelancing in web development & building blogs for people,  I have come to harbor an intense hatred for Wordpress. Wordpress is a mess and it sucks. The community sucks even more. God help you if you need to extend your system, as third-party plugins & themes trap you in bloated Javascript frameworks & dependency hell. Developing for Wordpress makes me want to die.

I desire nothing more than to see the Wordpress ecosystem abandoned en masse for a platform that is actually streamlined for the 21st century.

Furthermore, a quick survey of the code produced by popular AI website generators revealed **absolutely unreadable spaghetti code out the wazoo**, so that even if you managed to escape the walled gardens and self-host your site, you still wouldn't be able to figure out how to edit it without the AI.

Brothers, truly I tell you: ***There is a better way.***


## Useful Links

Documentation Index: [docs/README.md](docs/README.md)

Official Site: [raven.lanterns.io](https://raven.lanterns.io)

Packagist: [noveltylanterns/raven](https://packagist.org/packages/noveltylanterns/raven)


## Roadmap

- **0.8 (Current)**: Initial public release with basic panel & extension system.
- **0.9 (Next)**: Expanded theming system.
- **1.0 (Immediate Goal)**: First round UI polish, bug sweep, hardening & optimization.


## Caveats

- This application is very much still a proof-of-concept prototype.
- I have not been able to personally verify the contents of every file.
- Raven has not gone through a proper full security audit yet.
- There are still some pretty nonsensical things I've caught the clanker doing, so some of the code might be horribly inefficient. *(But it is at least readable! So we can hammer that one out in time.)*
