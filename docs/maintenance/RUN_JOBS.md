# Running MediaWiki Jobs

This document explains how to run MediaWiki maintenance jobs, particularly useful for SemanticSchemas operations.

## Running Jobs in Test Environment

If you're using the Docker-based test environment, you can run jobs with:

```bash
cd ~/.cache/semanticschemas/mediawiki-SemanticSchemas-test/
docker compose exec wiki php maintenance/runJobs.php --wait
```

### What This Does

- `--wait`: Waits for all pending jobs to complete before returning
- Executes all queued MediaWiki jobs (SMW updates, page refreshes, etc.)

### Common Use Cases

After importing a schema or making changes, you may need to run jobs to:

- Refresh Semantic MediaWiki data
- Update category links
- Process template changes
- Refresh parser cache

## Alternative: Run Jobs via Web Interface

You can also trigger job execution through the MediaWiki web interface:

1. Visit `Special:RunJobs`
2. Click "Run Jobs" to process the queue

## In Production

For production environments, jobs are typically run via:

1. **Cron job**: Set up a scheduled task to run jobs periodically
   ```bash
   */5 * * * * cd /path/to/mediawiki && php maintenance/runJobs.php --maxjobs=100
   ```

2. **Job queue system**: Use MediaWiki's job queue configuration for better control

## Related Documentation

- [Test Environment Setup](../../tests/README.md)
- [Main README](../../README.md)
