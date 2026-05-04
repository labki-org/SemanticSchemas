# SemanticSchemas Quick Start Guide

## Installation

1. **Install the extension:**
   ```bash
   cd /path/to/mediawiki/extensions/
   git clone [your-repo] SemanticSchemas
   cd SemanticSchemas
   composer install --no-dev
   ```

2. **Enable in LocalSettings.php:**
   ```php
   wfLoadExtension( 'SemanticSchemas' );
   ```

3. **Update database:**
   ```bash
   php maintenance/update.php
   ```

## Creating Your First Ontology

### Step 1: Create Properties

Create Property pages with SMW datatypes:

**Property:Has full name**
```
This is the person's full name.
[[Has type::Text]]
[[Category:Properties]]
```

**Property:Has email**
```
This is the person's email address.
[[Has type::Email]]
[[Category:Properties]]
```

### Step 2: Create a Category with Schema Metadata

**Category:Person**
```
A person in our organization.

<!-- SemanticSchemas Schema Start -->
=== Required Properties ===
[[Has required property::Property:Has full name]]
[[Has required property::Property:Has email]]

=== Optional Properties ===
[[Has optional property::Property:Has biography]]
<!-- SemanticSchemas Schema End -->
```

### Step 3: Generate Templates and Forms

**Via Special Page:**
1. Go to `Special:SemanticSchemas/generate`
2. Select "Person" category
3. Click "Generate"

**Via CLI:**
```bash
php extensions/SemanticSchemas/maintenance/regenerateArtifacts.php --category=Person --generate-display
```

This creates:
- `Template:Person/semantic` - Stores semantic data
- `Template:Person` - Dispatcher template
- `Template:Person/display` - Display stub (edit freely)
- `Form:Person` - PageForms form

## Working with Multiple Inheritance

Create a category with multiple parents:

**Category:GraduateStudent**
```
A graduate student.

<!-- SemanticSchemas Schema Start -->
[[Category:Person]]
[[Category:LabMember]]

=== Required Properties ===
[[Has required property::Property:Has advisor]]
<!-- SemanticSchemas Schema End -->
```

GraduateStudent will inherit all properties from both Person and LabMember, plus add its own required property "Has advisor".

## Common Tasks

### Add a New Property to Existing Category

Add to the category page:
```
[[Has required property::Property:Has phone]]
```

Then regenerate artifacts:
```bash
php extensions/SemanticSchemas/maintenance/regenerateArtifacts.php --category=Person
```

### Validate Your Ontology

Go to `Special:SemanticSchemas/validate` and click "Run Validation" to check for:
- Circular dependencies
- Missing properties
- Invalid references
- Unused properties

### Compare Schema with Wiki State

Via Special:SemanticSchemas/diff:
1. Upload or paste schema
2. See differences
3. Apply if desired

## Customizing Display Templates

The display template (`Template:Person/display`) is generated as a stub but **never overwritten**. Edit it freely:

```html
<div class="person-header">
  <h1>{{{full_name}}}</h1>
  {{#if:{{{email|}}}|
    <div class="contact">Email: {{{email}}}</div>
  }}
</div>

<div class="person-details">
  == Biography ==
  {{{biography}}}
</div>
```

## Tips and Best Practices

- **Use validation frequently** - Catch issues early
- **Don't edit semantic/dispatcher templates** - They're auto-generated
- **Customize display templates freely** - They're yours to edit
- **Use meaningful property names** - Start with "Has " for consistency
- **Document your properties** - Add descriptions on Property pages
- **Test inheritance carefully** - Multiple parents can be complex

## Troubleshooting

**"Property X does not exist" error:**
- Create the Property page first
- Make sure it has `[[Has type::...]]` annotation

**Templates not updating:**
- Run regenerateArtifacts.php
- Check write permissions
- Verify SMW is working

**Display looks broken:**
- Edit Template:Category/display
- Add CSS to MediaWiki:Common.css
- Check parameter names match

## Getting Help

- Check [Implementation Summary](../developer/IMPLEMENTATION.md) for technical details
- See [Main README](../../README.md) for full documentation
- Review [Architecture Guide](../developer/architecture.md) for technical architecture
- MediaWiki logs: `debug.log`
- Extension logs: Look for "SemanticSchemas:" prefix

