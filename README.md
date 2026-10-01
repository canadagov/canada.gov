# Canada.gov

Ask a question about government services in Canada and get a short answer
drawn from official pages, in English or French. Modelled on
[america.gov](https://america.gov/).

This is an independent demonstration. It is not a Government of Canada website
and says so on every page.

## Run it

You need PHP with the curl and mbstring extensions. It was built and tested on PHP 8.5.

```sh
cp .env.example .env        # then add your Hopscotch API key
php bin/models.php          # lists the Canadian models your key can call
php -S localhost:8000 -t public
```

Open <http://localhost:8000>. Add `?lang=fr` for French.

Set `HOPSCOTCH_MODEL` in `.env` to one of the ids `bin/models.php` prints. Only
models from Canadian labs are accepted; anything else is refused before a
request is made.

### Without a key

Stand-ins for the Hopscotch and Unsplash APIs let you work on the site without
keys or credit:

```sh
php -S localhost:8787 tests/fake-hopscotch.php
php -S localhost:8791 tests/fake-unsplash.php
HOPSCOTCH_BASE_URL=http://localhost:8787 HOPSCOTCH_API_KEY=test \
UNSPLASH_BASE_URL=http://localhost:8791 UNSPLASH_ACCESS_KEY=test \
  php -S localhost:8000 -t public
```

## Photos

The cards behind the example questions show photos of Canada from
[Unsplash](https://unsplash.com/). Set `UNSPLASH_ACCESS_KEY` in `.env` to turn
them on; without it the cards show gradients.

- Each card searches for one place (`UNSPLASH_PLACES` in `src/unsplash.php`),
  from Moraine Lake to Gros Morne.
- Only photographers whose Unsplash profile says they are based in Canada are
  used. That is the photographer's own description of where they are, not a
  check of citizenship, and a profile with no location is left out. A card
  with no such photo shows its gradient.
- The photos change once a day and are the same for every visitor in between.
  At the first page load of each day (Toronto time) the server searches again,
  moves each card on to the next photo in its results, and caches the choice.
  No other page load calls the API.
- That is at most 20 requests a day: ten searches, and one report per photo
  put to use, which Unsplash asks for. The free tier allows 50 an hour.
- Each card credits its photo as "Photo by (name) on Unsplash", linking the
  photo, the photographer and Unsplash.
- Photos load in the visitor's browser straight from Unsplash's image servers,
  as the Unsplash API terms require.

## How an answer is made

1. A small Cohere model reads the conversation and words a search: keywords,
   the language, and the province or territory if the person has named one.
2. The search runs against an index of official pages (`data/index.sqlite`),
   built by `bin/crawl.php`. Each page is cut into passages; the best passages
   of the five best pages are used. With a province named, other provinces'
   pages are left out. A page indexed more than a week ago is read again from
   the official site first.
3. Those passages go to a Cohere model through Hopscotch, with instructions to
   answer only from them, cite the page after each fact, and ask which
   province the person is in when the answer depends on it.
4. The answer streams back. The browser drops any link that is not to one of
   the pages retrieved in this conversation, so a made-up address never
   becomes a link, even one on a government domain.
5. The small model suggests two follow-up questions.

Conversations are held in the browser tab and nowhere else. The server writes
nothing about them to disk and sets no cookies. When photos are turned on, the
visitor's browser requests them from Unsplash, which sees those requests.

### The easter egg

Ask how to help the Leafs win the Stanley Cup and the answer is a poem
(`src/leafs.php`), in English or French, with no model called. It ends on a
photo of 1967 if `public/assets/leafs-1967.jpg` exists. That file is not in the
repository, because photos of the 1967 win are in copyright: add one you have
a licence for and name its owner in `LEAFS_PHOTO_CREDIT`.

## The search index

```sh
php bin/crawl.php
```

This reads the sites listed in `data/crawl.json`, starting from each site's
`start` pages and following links in page content for as long as they match
its `include` pattern, nearest first, up to `max_pages`. Each page's
counterpart in the other official language is read too. The hand-picked pages
in `data/sources.json` are always included.

- It is slow on purpose: one request at a time to each site, at most one a
  second, obeying `robots.txt`. A full run takes an hour or two. Stop it whenever
  you like; running it again carries on where it left off.
- After three refusals in a row a site is left alone for the rest of the run.
  Canada.ca cuts off a client that asks for its sitemaps or its search page,
  so the crawler uses neither.
- `php bin/crawl.php --site=ontario --limit=20` tries it on a few pages.
- The index is not in version control. Until it is built, answers come from
  the catalogue alone.

To add a province, add an entry to `data/crawl.json` and its domain to
`OFFICIAL_DOMAINS` in `src/config.php`.

## The source catalogue

`data/seeds.json` lists hand-picked official pages, with keywords in both
languages. They are used when there is no index, and are always part of it.
After editing the list, rebuild:

```sh
php bin/build-catalogue.php
```

This fetches every page, reads its title and its French counterpart from the
page itself, and drops anything that does not load.

## Link previews

When the site is shared, the preview shows `public/assets/og-en.png` or
`og-fr.png`, by language. After changing the greeting, the tagline, the first
example or the banner in `src/i18n.php`, draw them again:

```sh
php bin/og-image.php
```

This needs Chrome on the machine that runs it. Previews use full addresses,
so set `SITE_URL` if the site moves from `canada-gov.canner.app`.

## Deploy

The site runs on [Canner](https://canner.ca/) at
<https://canada-gov.canner.app>. To put the current code and search index
live:

```sh
npx @canner-ca/cli login    # once, with an API token from the Canner dashboard
bin/deploy.sh
```

- Keys are not uploaded. They are set on the Canner project:
  `npx @canner-ca/cli env set HOPSCOTCH_API_KEY=... --sensitive`.
- The project is set to PHP 8.4, the newest Canner has.
- Canner serves the site with PHP's built-in server. `PHP_CLI_SERVER_WORKERS`
  is set to 6 on the project so that six requests can be handled at once;
  without it, one streaming answer holds up every other visitor.
- Each deploy replaces the files on the server, the search index included. Run
  the crawler locally, then deploy.

## Limits

- The index covers Canada.ca service pages and the Ontario, Québec and British
  Columbia sites, a few thousand pages in all. It is not everything those
  governments publish, and other provinces, territories and cities are not in
  it yet.
- British Columbia's pages are in English only, so French questions about
  B.C. find little.
- There is no rate limiting. Each answer makes three model calls. Set a spend
  limit on the Hopscotch key before putting this anywhere public.
- The previews under "More coming in 2027" are illustrations only.

## Layout

```
public/index.php       the page
public/api/chat.php    the answer stream
public/assets/         styles, script, fonts
src/                   configuration, text, search, Hopscotch and Unsplash clients
data/                  the crawl list, the source catalogue, the search index
bin/                   crawler, catalogue builder, model lister, preview image, deploy script
tests/                 stand-in Hopscotch and Unsplash servers
```

See [TECHNOLOGY.md](TECHNOLOGY.md) for where every piece of technology comes
from.
