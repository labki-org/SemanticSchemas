# Changelog

All notable changes to SemanticSchemas will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Schema-driven ontology management for MediaWiki
- Automatic template, form, and category generation from JSON/YAML schemas
- Three-template system: dispatcher, semantic, and display templates
- C3 linearization for multiple inheritance resolution
- Schema validation with detailed error messages and warnings
- Category hierarchy visualization
- Form preview with parent category selection
- Hash-based dirty detection for external modifications
- Rate limiting for expensive operations
- Subobject support with semantic storage
- Custom namespace (Subobject:) for subobject pages
- CI/CD pipeline with GitHub Actions

### Configuration Options
- `$wgSemanticSchemasRequireApiAuth` - Require authentication for hierarchy API (default: false)
- `$wgSemanticSchemasRateLimitPerHour` - Rate limit for generate operations (default: 20)

### Dependencies
- MediaWiki >= 1.39.0
- PHP >= 8.1.0
- Semantic MediaWiki
- PageForms

## [0.1.0] - Initial Development

### Added
- Basic schema import/export functionality
- Template generation from category definitions
- Property and category management via Special:SemanticSchemas
