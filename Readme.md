# Report generator for NeosCMS based

This package provides an Excel report generator for NeosCMS.

You can configure report profiles via yaml and each can define 
which nodetypes should be included and the columns for each of those.
An optional starting point allows to only export parts of your site.

A backend module shows a list of all configured profiles and allows to export them.

## Installation

Run the following command in your Neos project:

```shell
composer require shel/neos-reports
```

Or add the package as dependency to your site-package.

## Configuration example for a report preset

```yaml
Shel:
  Neos:
    Reports:
      presets: 
        myReport:
          label: 'My Report'
          startingPoint: '/sites'
          filenamePrefix: 'My report'
          dateTimeFormats: 
            input: 'd.m.Y'
            values: 'd.m.Y H:i'
            filename: 'Y-m-d_H-i'
          nodeTypes:
            My.Vendor:Content.Event:
              sortBy: 'title'
              properties:
                - title
                - category
                - startDate
                - startTime
                - endDate
                - endTime
                - location
                - image
              expressions:
                # Get title from first content element
                Headline: "${q(node).children('content').first().property('title')}"
  
            My.Vendor:Document.News:
              sortBy: 'title'
              properties:
                - title
                - publicationDateTime
              expressions:
                # Get text from the first content node in main content collection and remove html tags
                Text: "${String.stripTags(q(node).children('main').children('[instanceof My.Vendor:Content.Text]').first().property('content'))}"
  
            My.Vendor:Content.text:
              sortBy: 'text'
              properties:
                - text
```

## Contribute

Contributions are very welcome.

For code contributions, please create a fork and create a PR against the lowest maintained
branch of this repository (currently `main`).

## License

See [License](LICENSE.txt)
