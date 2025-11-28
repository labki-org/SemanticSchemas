# StructureSync Quick Reference

Quick reference guide for common tasks with StructureSync.

## Hierarchy Visualization

### View Hierarchy in Special Page
```
Special:StructureSync/hierarchy?category=CategoryName
```

### Embed in Category Page
```wiki
{{#structuresync_hierarchy:}}
```

### API Call
```bash
curl "http://wiki.example.org/api.php?action=structuresync-hierarchy&category=Faculty&format=json"
```

## Property Views

The properties section has two tabs:

| Tab | View |
|-----|------|
| **By Category** | Properties grouped by source category (default) |
| **By Type** | Properties grouped as Required/Optional with counts |

## Color Coding

| Color | Meaning |
|-------|---------|
| 🟥 Red/Pink | Required property |
| 🟩 Green | Optional property |

## Common Workflows

### 1. Understand a Category Structure
1. Go to `Special:StructureSync/hierarchy`
2. Enter category name (e.g., "PhDStudent")
3. Review inheritance tree and properties

### 2. Document Category Structure
1. Edit your Category page
2. Add: `{{#structuresync_hierarchy:}}`
3. Save and view the embedded visualization

### 3. Export Hierarchy Data
```bash
curl "http://wiki/api.php?action=structuresync-hierarchy&category=NAME&format=json" > hierarchy.json
```

## See Also

- [Full Hierarchy Documentation](hierarchy-visualization.md)
- [Main README](../README.md)
- [Implementation Notes](../IMPLEMENTATION.md)

