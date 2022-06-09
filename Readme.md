# Report generator for NeosCMS based

## Configuration example for a report preset

```yaml
Shel:
  Neos:
    Reports:
      presets: 
        myReport:
          label: 'My Report'
          filenamePrefix: 'My report'
          dateTimeFormat: 'd.m.Y'
          dateTimeFormatForValues: 'd.m.Y H:i'
          dateTimeFormatForFilename: 'Y-m-d'
          startingPoint: '/sites'
          nodeTypes:
            My.Vendor:Content.Event:
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
              properties:
                - title
                - publicationDateTime
              expressions:
                # Get text from the first content node in main content collection and remove html tags
                Text: "${String.stripTags(q(node).children('main').children('[instanceof My.Vendor:Content.Text]').first().property('content'))}"
  
            My.Vendor:Content.text:
              properties:
                - text
```
