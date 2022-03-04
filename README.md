# itchy - scratches an itch with itch.io

When you buy bundles at itch.io to support good causes you end up with a huge library of all kinds of stuff. Finding what you are interested in, is hard. Because itch.io's client and website have no simple was to search and filter your own library.

This command line PHP tool scratches that itch. It will download all available information from itch.io, store it in a database and let you search through the contents.

## Installation

1. clone this repository
2. install the dependencies `composer install`

## Filling the database

Get an API key from https://itch.io/user/settings/api-keys then run the `update` command:

```
./itchy.php update <yourapikey>
```

## Searching

Use the search command. Giving no arguments will show you whole library:

```
./itchy.php search
```

You can use the `-f` parameter to show longer descriptions

```
./itchy.php search -f
```

Give search terms as arguments

```
./itchy.php search -f witch punk
```

Prefix terms with `+` or `-` to include or exclude tags

```
./itchy.php search -f witch punk +tag-horror -tag-ttrpg
```
