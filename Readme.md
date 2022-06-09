# Report generator for NeosCMS based

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
