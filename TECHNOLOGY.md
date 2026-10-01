# Technology

The rule for this project: use Canadian technology wherever a Canadian option
exists. This file lists everything the site uses, where it comes from, and what
has no Canadian equivalent.

## AI

Both Canadian. Answers are written by a Cohere model, reached through Hopscotch.

| What | Technology | Origin |
| --- | --- | --- |
| AI model | Cohere Command (`cohere/command-a-03-2025` for answers, `cohere/command-r7b-12-2024` for wording searches) | Cohere, Toronto. The server refuses any model that is not from a Canadian lab (`CANADIAN_MAKERS` in `src/config.php`). |
| Model router | Hopscotch (`api.hopscotchlabs.ai`) | Canadian, OpenRouter alternative built in Toronto. Every request to Cohere goes through it. |

## Canadian

| What | Technology | Origin |
| --- | --- | --- |
| Hosting | Canner (`canada-gov.canner.app`) | Montréal. Canadian-owned, with its servers in Québec. It runs the site on PHP 8.4, using PHP's own built-in web server with six workers. |
| Server language | PHP | Created in 1994 by Rasmus Lerdorf, a Danish-Canadian and University of Waterloo graduate. It is maintained today by an international open-source community. |
| Headline typeface | Castoro | Tiro Typeworks, British Columbia. Open Font License, self-hosted. |
| Text typeface | Radio Canada | Charles Daoud with Coppers and Brasses, Montréal, made for CBC/Radio-Canada. Open Font License, self-hosted. |
| Content | Canada.ca and other official sites | Pages are read from the official source by this site's own crawler, and read again when they are more than a week old. |
| Application code | No framework, no libraries | The crawler, search, the streaming client, the Markdown renderer and all styling are written in this repository. The site itself uses no npm, Composer, React, Tailwind or analytics; npm appears only at deploy time, to run Canner's command-line tool. The only files served from elsewhere are the Unsplash photos. |
| Languages | English and French | Both official languages, on every screen. |

## Requested, Canadian-founded, now foreign-owned

| What | Technology | Note |
| --- | --- | --- |
| Photos | Unsplash API | Founded in Montréal in 2013 and still based there; owned by Getty Images (USA) since 2021. Optional: the site works without it. Its API terms require photos to load from Unsplash's own image servers, so visitors' browsers contact Unsplash directly. |

## International standards

These are the foundations of the web. They belong to no one country, and
nothing replaces them, Canadian or otherwise.

| What | Technology | Origin |
| --- | --- | --- |
| Page structure and style | HTML, CSS | W3C and WHATWG (international standards) |
| Browser scripting | JavaScript | Ecma International standard; created at Netscape, USA |
| Transport | HTTP, TLS, server-sent events, JSON, Unicode | International standards |
| Vector graphics | SVG | W3C standard |
| Motion | CSS animations and transitions, the Web Animations API, view transitions | W3C standards, built into the browser. Every animation is written in `app.css` and `app.js`; there is no animation, carousel or spring library. |

## No Canadian alternative

Software from other countries that the server depends on.

| What | Technology | Origin |
| --- | --- | --- |
| Outbound HTTPS from PHP | libcurl and OpenSSL, through PHP's curl extension | Sweden and an international project |
| Search index | SQLite full-text search, through PHP's sqlite3 extension | USA (D. Richard Hipp); public domain. Coveo, below, is the Canadian search product, but it is a hosted service rather than something this repository can carry. |

## Used to build it, not shipped

None of these run when someone visits the site.

| What | Technology | Origin | Canadian alternative |
| --- | --- | --- | --- |
| Writing the code | Claude Code | Anthropic, USA | Cohere's models could do this through another tool; not set up here. |
| Version control | Git | Finland and USA | None |
| Installing PHP | Homebrew | USA | None on macOS |
| Deploying | Canner's command-line tool (`@canner-ca/cli`), run by `bin/deploy.sh` | Canner, Montréal | Already Canadian |
| Running the deploy tool | Node.js and npm (`npx`) | USA; the npm registry is owned by GitHub (Microsoft) | None: Canner publishes its tool on npm |
| Copying files and the search index for upload | rsync and the `sqlite3` command-line tool | Australia and USA | None |
| Fetching the font files once | Fontsource via jsDelivr | International | The foundries' own downloads |
| Drawing the link preview images | Chrome, run without a window by `bin/og-image.php` | USA | None |
| Development machine and browser | macOS, Chrome | USA | None |

## Not chosen yet

Decisions still open. The Canadian option is listed first.

| What | Canadian option | Otherwise |
| --- | --- | --- |
| Domain | A `.ca` domain, run by CIRA. `canada.gov` itself cannot be registered: `.gov` is reserved for United States governments. | For now the site is at the address Canner gives it, `canada-gov.canner.app`. `.app` is run by Google (USA). |
| Search across all government pages | Coveo, Québec City, which already powers search on Canada.ca. Would replace this site's own crawler and index. | |
