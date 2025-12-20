#!/usr/bin/env bash

set -euo pipefail

#
# StructureSync — Populate comprehensive test data script
# This script creates a diverse set of test data for manual testing of StructureSync
#

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

REPO_ROOT="$(dirname "$(dirname "$SCRIPT_DIR")")"
MW_DIR="${MW_DIR:-$REPO_ROOT}"

if [ ! -d "$MW_DIR" ]; then
    echo "ERROR: MediaWiki directory not found at: $MW_DIR"
    echo "Run setup_mw_test_env.sh first"
    exit 1
fi

cd "$MW_DIR"

# Ensure cache directory is writable (fix for LocalisationCache warnings)
echo "==> Ensuring cache directory permissions..."
docker compose exec -T wiki bash -c "mkdir -p /tmp/my_wiki && chmod 777 /tmp/my_wiki" 2>/dev/null || true

# Helper function to create a property
create_property() {
    local name="$1"
    local description="$2" 
    local type="$3"
    local extra="$4"  # Additional annotations (optional)
    
    docker compose exec -T wiki bash -c "php maintenance/edit.php -b 'Property:$name' <<'PROPEOF'
<!-- StructureSync Start -->
[[Has type::$type]]
[[Has description::$description]]
$extra
<!-- StructureSync End -->

[[Category:Properties]]
PROPEOF
"
}

# Helper function to create a category
create_category() {
    local name="$1"
    local content="$2"
    
    docker compose exec -T wiki bash -c "php maintenance/edit.php -b 'Category:$name' <<'CATEOF'
$content
CATEOF
"
}

# Helper function to create a subobject definition
create_subobject() {
    local name="$1"
    local content="$2"
    
    docker compose exec -T wiki bash -c "php maintenance/edit.php -b 'Subobject:$name' <<'SUBEOF'
$content
SUBEOF
"
}

echo "=========================================="
echo "Creating comprehensive test data for StructureSync"
echo "=========================================="
echo ""

echo "==> Creating test properties..."

# ==========================================
# 0. Meta-Properties (Must be created first)
# ==========================================
create_property "Has display template" "Template for displaying property values." "Page" ""
create_property "Has template" "Points to a template for generic usage." "Page" ""

# ==========================================
# Core Meta-Properties
# ==========================================
echo "  - Core meta-properties (created by extension-config.json)..."

# ==========================================
# Property Type 1: Text Properties
# ==========================================
echo "  - Text properties..."
create_property "Has full name" "The full name of a person." "Text" "[[Display label::Full Name]]"

# Biography with custom display template (using wikitext with HTML allowed via rawhtml extension if needed)
# For now, using simple wikitext - users can customize the template page directly for styling
create_property "Has biography" "Biography or description text." "Text" "[[Has template::Template:Property/Typography]]"

create_property "Has research interests" "Research interests and expertise areas." "Text" "[[Display label::Research Interests]]"
create_property "Has office location" "Office or workspace location." "Text" ""

# ==========================================
# Property Type 2: Contact Information
# ==========================================
echo "  - Contact information properties..."

# Create display pattern properties (templates that other properties can reference)
# Use double-bracket syntax for reliable parsing in template contexts
echo "  - Display pattern properties..."


# Contact properties using display patterns
create_property "Has email" "Email address." "Email" "[[Has template::Template:Property/Email]]"
create_property "Has phone" "Phone number." "Telephone number" ""
create_property "Has website" "Personal or lab website URL." "URL" "[[Has template::Template:Property/Link]]"
create_property "Has orcid" "ORCID identifier (e.g., 0000-0000-0000-0000)." "Text" ""

# ==========================================
# Property Type 3: Date/Time Properties
# ==========================================
echo "  - Date/time properties..."
create_property "Has birth date" "Date of birth." "Date" ""
create_property "Has start date" "Start date (employment, enrollment, etc.)." "Date" "[[Display label::Start Date]]"
create_property "Has end date" "End date (graduation, departure, etc.)." "Date" "[[Display label::End Date]]"
create_property "Has publication date" "Date of publication." "Date" ""

# ==========================================
# Property Type 4: Numeric Properties
# ==========================================
echo "  - Numeric properties..."
create_property "Has cohort year" "Year of cohort or class." "Number" ""
create_property "Has publication count" "Number of publications." "Number" "[[Display label::Publication Count]]"
create_property "Has h index" "H-index metric." "Number" "[[Display label::H Index]]"
create_property "Has room number" "Office or room number." "Number" ""

# ==========================================
# Property Type 5: Boolean Properties
# ==========================================
echo "  - Boolean properties..."
create_property "Has active status" "Whether the person is currently active." "Boolean" ""
create_property "Has public profile" "Whether profile is publicly visible." "Boolean" ""

# ==========================================
# Property Type 6: Page/Reference Properties
# ==========================================
echo "  - Page/reference properties..."
create_property "Has advisor" "Academic advisor or supervisor." "Page" "[[Display label::Advisor]]"
create_property "Has lab" "Lab or research group affiliation." "Page" "[[Display label::Lab]]"
create_property "Has institution" "Institutional affiliation." "Page" "[[Display label::Institution]]"
create_property "Has department" "Department affiliation." "Page" "[[Display label::Department]]
[[Allows value from category::Department]]
[[Allows multiple values::true]]"
create_property "Has collaborator" "Research collaborators." "Page" "[[Allows multiple values::true]]"

# ==========================================
# Property Type: Publication Subobject Fields
# ==========================================
echo "  - Publication subobject properties..."
create_property "Has author" "Author referenced by a publication." "Page" "[[Allows value from category::Person]]"
create_property "Has author order" "Ordering index for publication authors." "Number" ""
create_property "Is co-first author" "Marks whether the author is co-first." "Boolean" ""
create_property "Is corresponding author" "Marks whether the author is corresponding." "Boolean" ""

# ==========================================
# Property Type 7: Properties with Allowed Values
# ==========================================
echo "  - Properties with allowed values..."
create_property "Has lab role" "Role in the lab." "Text" "[[Allows value::PI]]
[[Allows value::Lab Manager]]
[[Allows value::Postdoc]]
[[Allows value::Graduate Student]]
[[Allows value::Undergraduate]]
[[Allows value::Research Assistant]]
[[Allows value::Visitor]]"

create_property "Has academic level" "Academic level or degree status." "Text" "[[Display label::Academic Level]]
[[Allows value::Undergraduate]]
[[Allows value::Masters]]
[[Allows value::PhD]]
[[Allows value::Postdoc]]
[[Allows value::Faculty]]"

create_property "Has employment status" "Employment or appointment status." "Text" "[[Allows value::Full-time]]
[[Allows value::Part-time]]
[[Allows value::Contract]]
[[Allows value::Volunteer]]"

# ==========================================
# Property Type 8: Specialized Properties
# ==========================================
echo "  - Specialized properties..."
create_property "Has geographic location" "Geographic coordinates (lat, lon)." "Geographic coordinate" ""
create_property "Has code repository" "URL to code repository (GitHub, GitLab, etc.)." "URL" ""

# ==========================================
# Property Type 9: Academic/Research Properties
# ==========================================
echo "  - Academic/research properties..."
create_property "Has degree" "Academic degree obtained." "Text" ""
create_property "Has thesis title" "Title of thesis or dissertation." "Text" ""
create_property "Has research area" "Primary research area." "Text" ""
create_property "Has keywords" "Research keywords." "Text" "[[Allows multiple values::true]]"

echo ""
echo "==> Creating test categories with schema..."

# ==========================================
# Base Categories (no parents)
# ==========================================
echo "  - Base categories..."

# Base Person category (simple base category with basic schema)
create_category "Person" "[[Has description::A person in our organization.]]

=== Required Properties ===
[[Has required property::Property:Has full name]]
[[Has required property::Property:Has email]]

=== Optional Properties ===
[[Has optional property::Property:Has phone]]
[[Has optional property::Property:Has biography]]
[[Has optional property::Property:Has website]]
[[Has optional property::Property:Has birth date]]

[[Has template::Template:Category/table]]"

# LabMember category (base category for lab members)
create_category "LabMember" "[[Has description::A member of the lab.]]

=== Required Properties ===
[[Has required property::Property:Has lab role]]
[[Has required property::Property:Has start date]]

=== Optional Properties ===
[[Has optional property::Property:Has biography]]
[[Has optional property::Property:Has end date]]
[[Has optional property::Property:Has active status]]"

# Organization category (base category, no parents)
create_category "Organization" "[[Has description::An organization or institution.]]

=== Required Properties ===
[[Has required property::Property:Has full name]]

=== Optional Properties ===
[[Has optional property::Property:Has website]]
[[Has optional property::Property:Has geographic location]]"

# Lab category (inherits from Organization)
create_category "Lab" "[[Has description::A research lab or group.]]

[[Has parent category::Category:Organization]]

=== Required Properties ===
[[Has required property::Property:Has lab]]

=== Optional Properties ===
[[Has optional property::Property:Has research area]]
[[Has optional property::Property:Has code repository]]
[[Has optional property::Property:Has website]]

{{#subobject:display_section_0
|Has display section name=Research
|Has display section property=Property:Has research area
}}

[[Category:Organization]]"

# Publication category (standalone category)
create_category "Publication" "[[Has description::A research publication.]]

=== Required Properties ===
[[Has required property::Property:Has full name]]
[[Has required property::Property:Has publication date]]

=== Optional Properties ===
[[Has optional property::Property:Has keywords]]
[[Has optional property::Property:Has website]]

=== Required Subobjects ===
[[Has required subobject::Subobject:PublicationAuthor]]

{{#subobject:display_section_0
|Has display section name=Publication Details
|Has display section property=Property:Has publication date
|Has display section property=Property:Has keywords
}}"

echo "  - Subobject definitions..."

create_subobject "PublicationAuthor" "[[Has description::Captures publication author entries (repeatable).]]

[[Has required property::Property:Has author]]
[[Has required property::Property:Has author order]]

[[Has optional property::Property:Is corresponding author]]
[[Has optional property::Property:Is co-first author]]

[[Category:StructureSync-managed]]"

# Project category (base category)
create_category "Project" "[[Has description::A research project.]]

=== Required Properties ===
[[Has required property::Property:Has full name]]

=== Optional Properties ===
[[Has optional property::Property:Has start date]]
[[Has optional property::Property:Has end date]]
[[Has optional property::Property:Has research area]]"

echo "  - Single inheritance hierarchies..."

# Faculty category (inherits from Person only)
create_category "Faculty" "[[Has description::Faculty member.]]

[[Has parent category::Category:Person]]

=== Required Properties ===
[[Has required property::Property:Has department]]
[[Has required property::Property:Has institution]]

=== Optional Properties ===
[[Has optional property::Property:Has research interests]]
[[Has optional property::Property:Has publication count]]
[[Has optional property::Property:Has h index]]
[[Has optional property::Property:Has room number]]

{{#subobject:display_section_0
|Has display section name=Academic Information
|Has display section property=Property:Has department
|Has display section property=Property:Has institution
|Has display section property=Property:Has room number
}}

{{#subobject:display_section_1
|Has display section name=Research
|Has display section property=Property:Has research interests
|Has display section property=Property:Has publication count
|Has display section property=Property:Has h index
}}

[[Category:Person]]"

# Student category (base for all students, inherits from Person)
create_category "Student" "[[Has description::A student.]]

[[Has parent category::Category:Person]]

=== Required Properties ===
[[Has required property::Property:Has advisor]]
[[Has required property::Property:Has academic level]]

=== Optional Properties ===
[[Has optional property::Property:Has cohort year]]
[[Has optional property::Property:Has degree]]

{{#subobject:display_section_0
|Has display section name=Academic Information
|Has display section property=Property:Has advisor
|Has display section property=Property:Has academic level
|Has display section property=Property:Has cohort year
}}

[[Category:Person]]"

# Undergraduate category (inherits from Student, single inheritance chain)
create_category "Undergraduate" "[[Has description::An undergraduate student.]]

[[Has parent category::Category:Student]]

=== Optional Properties ===
[[Has optional property::Property:Has employment status]]

{{#subobject:display_section_0
|Has display section name=Student Information
|Has display section property=Property:Has employment status
}}

[[Category:Student]]"

echo "  - Multiple inheritance hierarchies..."

# GraduateStudent category (multiple inheritance: Person + LabMember)
create_category "GraduateStudent" "[[Has description::A graduate student in the lab.]]

[[Has parent category::Category:Person]]
[[Has parent category::Category:LabMember]]

=== Required Properties ===
[[Has required property::Property:Has advisor]]
[[Has required property::Property:Has academic level]]

=== Optional Properties ===
[[Has optional property::Property:Has cohort year]]
[[Has optional property::Property:Has thesis title]]
[[Has optional property::Property:Has research interests]]

{{#subobject:display_section_0
|Has display section name=Academic Information
|Has display section property=Property:Has advisor
|Has display section property=Property:Has academic level
|Has display section property=Property:Has cohort year
|Has display section property=Property:Has thesis title
}}

{{#subobject:display_section_1
|Has display section name=Research
|Has display section property=Property:Has research interests
}}

[[Category:Person]]
[[Category:LabMember]]"

# Postdoc category (multiple inheritance: Person + LabMember)
create_category "Postdoc" "[[Has description::A postdoctoral researcher in the lab.]]

[[Has parent category::Category:Person]]
[[Has parent category::Category:LabMember]]

=== Required Properties ===
[[Has required property::Property:Has lab]]
[[Has required property::Property:Has start date]]

=== Optional Properties ===
[[Has optional property::Property:Has research interests]]
[[Has optional property::Property:Has publication count]]
[[Has optional property::Property:Has end date]]

{{#subobject:display_section_0
|Has display section name=Research
|Has display section property=Property:Has research interests
|Has display section property=Property:Has publication count
}}

[[Category:Person]]
[[Category:LabMember]]"

# PI category (Principal Investigator, inherits from Faculty + LabMember)
create_category "PI" "[[Has description::A principal investigator (lab head).]]

[[Has parent category::Category:Faculty]]
[[Has parent category::Category:LabMember]]

=== Required Properties ===
[[Has required property::Property:Has lab]]

=== Optional Properties ===
[[Has optional property::Property:Has orcid]]

{{#subobject:display_section_0
|Has display section name=Lab Information
|Has display section property=Property:Has lab
}}

[[Category:Faculty]]
[[Category:LabMember]]"

echo "  - Deep hierarchy examples..."

# PhDStudent category (deep inheritance: Person -> Student -> GraduateStudent + LabMember)
create_category "PhDStudent" "[[Has description::A PhD student in the lab.]]

[[Has parent category::Category:GraduateStudent]]

=== Optional Properties ===
[[Has optional property::Property:Has thesis title]]
[[Has optional property::Property:Has degree]]

{{#subobject:display_section_0
|Has display section name=PhD Information
|Has display section property=Property:Has thesis title
|Has display section property=Property:Has degree
}}

[[Category:GraduateStudent]]"

# MastersStudent category (deep inheritance: Person -> Student -> GraduateStudent)
create_category "MastersStudent" "[[Has description::A masters student.]]

[[Has parent category::Category:GraduateStudent]]

=== Optional Properties ===
[[Has optional property::Property:Has thesis title]]

{{#subobject:display_section_0
|Has display section name=Masters Information
|Has display section property=Property:Has thesis title
}}

[[Category:GraduateStudent]]"

echo "  - Namespace targeting categories..."

# UserProfile category (uses target namespace)
create_category "UserProfile" "[[Has description::A user profile page (created in User namespace).]]
[[Has target namespace::User]]

=== Required Properties ===
[[Has required property::Property:Has full name]]
[[Has required property::Property:Has email]]

=== Optional Properties ===
[[Has optional property::Property:Has biography]]
[[Has optional property::Property:Has website]]

{{#subobject:display_section_0
|Has display section name=User Information
|Has display section property=Property:Has full name
|Has display section property=Property:Has email
|Has display section property=Property:Has website
}}"

echo "  - Edge case categories..."

# EmptyCategory (category with no properties defined)
create_category "EmptyCategory" "[[Has description::A category with no properties (for testing).]]"

# SimpleCategory (category with minimal schema)
create_category "SimpleCategory" "[[Has description::A simple category for testing.]]

=== Required Properties ===
[[Has required property::Property:Has full name]]"

echo ""
echo "==> Refreshing Semantic MediaWiki data (before form generation)..."
echo "This ensures all semantic properties are parsed and available for template/form generation..."
docker compose exec -T wiki php extensions/SemanticMediaWiki/maintenance/rebuildData.php -f --skip-properties --report-runtime

echo ""
echo "==> Generating templates and forms..."
docker compose exec -T wiki php /mw-user-extensions/StructureSync/maintenance/regenerateArtifacts.php --generate-display

echo ""
echo "==> Creating example pages..."

# Helper function to create an example page
create_page() {
    local name="$1"
    local content="$2"
    
    docker compose exec -T wiki bash -c "php maintenance/edit.php -b '$name' <<'PAGEOF'
$content
PAGEOF
"
}

echo "  - Creating Department category for autocomplete demo..."

# Create Department category (for autocomplete demonstration)
create_category "Department" "[[Has description::An academic department within an institution.]]

=== Required Properties ===
[[Has required property::Property:Has full name]]

=== Optional Properties ==
[[Has optional property::Property:Has website]]"

echo "  - Creating department pages for autocomplete demo..."

# Create multiple department pages to demonstrate autocomplete
create_page "Biology" "Biology Department - Study of living organisms.

[[Category:Department]]"

create_page "Chemistry" "Chemistry Department - Physical science of matter.

[[Category:Department]]"

create_page "Computer Science" "Computer Science Department - Study of computation and information.

[[Category:Department]]"

create_page "Mathematics" "Mathematics Department - Study of numbers, quantity, and space.

[[Category:Department]]"

create_page "Physics" "Physics Department - Natural science of matter and energy.

[[Category:Department]]"

echo "  - Base category examples..."

# Example Person
create_page "John_Doe" "{{Person
|full_name=John Doe
|email=john.doe@example.edu
|phone=555-0100
|biography=John is a researcher with expertise in computational biology.
|website=https://johndoe.example.edu
|birth_date=1975-05-15
}}

[[Category:Person]]"

# Example Faculty
create_page "Dr_Alice_Johnson" "{{Faculty
|full_name=Dr. Alice Johnson
|email=alice.johnson@example.edu
|phone=555-0200
|department=Biology
|institution=Example University
|room_number=301
|research_interests=Computational biology, Systems biology, Machine learning
|publication_count=47
|h_index=23
|biography=Alice Johnson is a professor specializing in computational biology and systems biology.
}}

[[Category:Faculty]]"

# Example Lab
create_page "Johnson_Lab" "{{Lab
|full_name=Johnson Computational Biology Lab
|website=https://jlab.example.edu
|research_area=Computational Biology, Systems Biology, Bioinformatics
|code_repository=https://github.com/johnsonlab
}}

[[Category:Lab]]"

echo "  - Single inheritance examples..."

# Example Undergraduate
create_page "Bob_Williams" "{{Undergraduate
|full_name=Bob Williams
|email=bob.williams@example.edu
|advisor=Dr. Alice Johnson
|academic_level=Undergraduate
|cohort_year=2024
|employment_status=Part-time
|biography=Bob is an undergraduate student working on bioinformatics projects.
}}

[[Category:Undergraduate]]"

echo "  - Multiple inheritance examples..."

# Example Graduate Student (Person + LabMember)
create_page "Jane_Smith" "{{GraduateStudent
|full_name=Jane Smith
|email=jane.smith@example.edu
|phone=555-0101
|advisor=Dr. Alice Johnson
|cohort_year=2023
|lab_role=Graduate Student
|start_date=2023-09-01
|academic_level=PhD
|thesis_title=Machine Learning Approaches to Protein Structure Prediction
|research_interests=Machine learning, Protein folding, Deep learning
|biography=Jane is a PhD student in the Johnson lab working on protein structure prediction.
}}

[[Category:GraduateStudent]]"

# Example Postdoc (Person + LabMember)
create_page "Dr_Carlos_Rodriguez" "{{Postdoc
|full_name=Dr. Carlos Rodriguez
|email=carlos.rodriguez@example.edu
|phone=555-0300
|lab_role=Postdoc
|lab=Johnson Lab
|start_date=2022-09-01
|research_interests=Systems biology, Network analysis
|publication_count=15
|biography=Carlos is a postdoctoral researcher working on network biology approaches.
|website=https://carlos.example.edu
}}

[[Category:Postdoc]]"

# Example PI (Faculty + LabMember)
create_page "Dr_Alice_Johnson_PI" "{{PI
|full_name=Dr. Alice Johnson
|email=alice.johnson@example.edu
|phone=555-0200
|department=Biology
|institution=Example University
|room_number=301
|lab_role=PI
|lab=Johnson Lab
|start_date=2020-01-01
|research_interests=Computational biology, Systems biology
|publication_count=47
|h_index=23
|orcid=0000-0000-0000-0001
|biography=Alice Johnson leads the Johnson Computational Biology Lab.
}}

[[Category:PI]]"

echo "  - Deep hierarchy examples..."

# Example PhD Student (deep inheritance)
create_page "David_Chen" "{{PhDStudent
|full_name=David Chen
|email=david.chen@example.edu
|phone=555-0400
|advisor=Dr. Alice Johnson
|academic_level=PhD
|cohort_year=2021
|lab_role=Graduate Student
|start_date=2021-09-01
|thesis_title=Deep Learning for Biological Sequence Analysis
|degree=PhD in Computational Biology
|research_interests=Deep learning, Sequence analysis, Natural language processing for biology
|biography=David is a PhD student working on applying deep learning to biological sequences.
}}

[[Category:PhDStudent]]"

# Example Masters Student (deep inheritance)
create_page "Emma_Wilson" "{{MastersStudent
|full_name=Emma Wilson
|email=emma.wilson@example.edu
|advisor=Dr. Alice Johnson
|academic_level=Masters
|cohort_year=2024
|lab_role=Graduate Student
|start_date=2024-09-01
|thesis_title=Network Analysis of Protein-Protein Interactions
|research_interests=Network biology, Graph theory
|biography=Emma is a masters student working on network biology projects.
}}

[[Category:MastersStudent]]"

echo "  - Organization examples..."

# Example Organization
create_page "Example_University" "{{Organization
|full_name=Example University
|website=https://www.example.edu
|geographic_location=40.7128;-74.0060
}}

[[Category:Organization]]"

# Example Publication
create_page "Recent_Publication_2024" "{{Publication
|full_name=Machine Learning Approaches to Protein Folding
|publication_date=2024-01-15
|keywords=machine learning,protein folding,deep learning,bioinformatics
|website=https://example.edu/publications/ml-protein-folding
}}

{{Publication_PublicationAuthor
|author=Dr. Alice Johnson
|author_order=1
|is_corresponding_author=true
}}

{{Publication_PublicationAuthor
|author=Emma Wilson
|author_order=2
|is_co-first_author=true
}}

[[Category:Publication]]"

# Example Project
create_page "Protein_Folding_Project" "{{Project
|full_name=Deep Learning for Protein Folding Prediction
|start_date=2023-01-01
|research_area=Protein folding, Machine learning
}}

[[Category:Project]]"

echo ""


echo "==> Creating Template Infrastructure..."

# Templates 1-3 removed (Legacy dynamic display system)

# 4. Template:Property/Default
create_page "Template:Property/Default" "<includeonly>{{{value}}}</includeonly>"

# 5. Template:Property/Email
create_page "Template:Property/Email" "<includeonly>[mailto:{{{value|}}} {{{value|}}}]</includeonly>"

# 6. Template:Property/Link
create_page "Template:Property/Link" "<includeonly>[{{{value|}}} {{{value|}}}]</includeonly>"

# 7. Template:Property/Typography (for Biography)
create_page "Template:Property/Typography" "<includeonly>'''{{{value|}}}'''</includeonly>"

echo "  - Creating Category:Test Table View..."
create_category "Test Table View" "[[Has description::Category using property-driven table format.]]
[[Has display template::Property:Display format/Table]]
[[Has required property::Property:Has full name]]
[[Has required property::Property:Has email]]
[[Has optional property::Property:Has phone]]"

echo "  - Creating Test Page for Table View..."
create_page "Table_View_Test_Page" "{{Test Table View
|full_name=Table Verification User
|email=table@example.com
|phone=123-456-7890
}}
[[Category:Test Table View]]"

echo ""
echo "==> Refreshing Semantic MediaWiki data..."
echo "This may take a minute as SMW re-parses all pages to extract properties..."
docker compose exec -T mediawiki php extensions/SemanticMediaWiki/maintenance/rebuildData.php -f --skip-properties --report-runtime

echo ""
echo "========================================"
echo "Test data populated successfully!"
echo "========================================"
echo ""
echo "Created:"
echo ""
echo "PROPERTIES (35+):"
echo "  - Meta: Display label, Has description, Allows multiple values, Has target namespace, Has parent category, Has required property, Has optional property, Has required subobject, Has optional subobject"
echo "  - Text: Has full name, Has biography, Has research interests, Has office location"
echo "  - Contact: Has email, Has phone, Has website, Has orcid"
echo "  - Date/Time: Has birth date, Has start date, Has end date, Has publication date"
echo "  - Numeric: Has cohort year, Has publication count, Has h index, Has room number"
echo "  - Boolean: Has active status, Has public profile"
echo "  - Page/Reference: Has advisor, Has lab, Has institution, Has collaborator"
echo "  - With Autocomplete: Has department (demonstrates [[Allows value from category::Department]])"
echo "  - With Allowed Values: Has lab role, Has academic level, Has employment status"
echo "  - With Multiple Values: Has department, Has collaborator, Has keywords"
echo "  - Specialized: Has geographic location, Has code repository"
echo "  - Academic: Has degree, Has thesis title, Has research area, Has keywords"
echo ""
echo "CATEGORIES (18+):"
echo "  Base Categories (no parents):"
echo "    - Person (with display sections)"
echo "    - Organization, Lab, Publication, Project"
echo "    - LabMember"
echo "    - Department (for autocomplete demo)"
echo "    - UserProfile (demonstrates target namespace)"
echo "    - Category (meta-category for defining categories, uses Category namespace)"
echo "  Single Inheritance:"
echo "    - Faculty (Person -> Faculty)"
echo "    - Student (Person -> Student)"
echo "    - Undergraduate (Person -> Student -> Undergraduate)"
echo "  Multiple Inheritance:"
echo "    - GraduateStudent (Person + LabMember)"
echo "    - Postdoc (Person + LabMember)"
echo "    - PI (Faculty + LabMember)"
echo "  Deep Hierarchies:"
echo "    - PhDStudent (Person -> Student -> GraduateStudent + LabMember -> PhDStudent)"
echo "    - MastersStudent (Person -> Student -> GraduateStudent -> MastersStudent)"
echo "  Edge Cases:"
echo "    - EmptyCategory (no properties)"
echo "    - SimpleCategory (minimal schema)"
echo ""
echo "EXAMPLE PAGES (15+):"
echo "  - John_Doe (Person)"
echo "  - Dr_Alice_Johnson (Faculty)"
echo "  - Johnson_Lab (Lab)"
echo "  - Bob_Williams (Undergraduate)"
echo "  - Jane_Smith (GraduateStudent)"
echo "  - Dr_Carlos_Rodriguez (Postdoc)"
echo "  - Dr_Alice_Johnson_PI (PI)"
echo "  - David_Chen (PhDStudent)"
echo "  - Emma_Wilson (MastersStudent)"
echo "  - Example_University (Organization)"
echo "  - Recent_Publication_2024 (Publication)"
echo "  - Protein_Folding_Project (Project)"
echo "  - Biology, Chemistry, Computer Science, Mathematics, Physics (Departments for autocomplete)"
echo ""
echo "ARTIFACTS:"
echo "  - Templates and Forms generated for all categories"
echo "  - Exported schema to tests/test-schema.json"
echo ""
echo "========================================"
echo "TESTING SCENARIOS"
echo "========================================"
echo ""
echo "1. OVERVIEW & EXPORT:"
echo "   - Visit Special:StructureSync to see the overview"
echo "   - Check category hierarchy and property inheritance"
echo "   - Export schema via Special:StructureSync/export"
echo ""
echo "2. SINGLE INHERITANCE:"
echo "   - View Faculty category (Person -> Faculty)"
echo "   - Check that Faculty inherits Person properties"
echo "   - View Dr_Alice_Johnson page"
echo ""
echo "3. MULTIPLE INHERITANCE:"
echo "   - View GraduateStudent category (Person + LabMember)"
echo "   - Verify it inherits properties from both parents"
echo "   - View Jane_Smith page"
echo "   - Test PI category (Faculty + LabMember)"
echo ""
echo "4. DEEP HIERARCHIES:"
echo "   - View PhDStudent category (4-level inheritance)"
echo "   - Verify property inheritance across levels"
echo "   - View David_Chen page"
echo ""
echo "5. PROPERTY TYPES:"
echo "   - Test different datatypes (Text, Email, Date, Number, Boolean, Page, URL)"
echo "   - Test properties with allowed values (Has lab role)"
echo "   - Test Page type properties with references"
echo "   - Test autocomplete from category (Has department → Category:Department)"
echo ""
echo "6. DISPLAY SECTIONS:"
echo "   - View Person category pages to see display sections"
echo "   - Check that display templates are generated"
echo ""
echo "7. FORMS:"
echo "   - Use Form:Person to create a new person"
echo "   - Use Form:GraduateStudent to create a new graduate student"
echo "   - Test form validation for required properties"
echo ""
echo "8. VALIDATION:"
echo "   - Run validation at Special:StructureSync/validate"
echo "   - Check for missing templates, forms, or inconsistencies"
echo ""
echo "9. DIFF:"
echo "    - Use Special:StructureSync/diff to compare schemas"
echo "    - Test with modified schema files"
echo ""
echo "10. EDGE CASES:"
echo "    - View EmptyCategory (no properties)"
echo "    - View SimpleCategory (minimal schema)"
echo "    - Test categories with many properties (Faculty)"
echo ""
echo "11. GENERATE:"
echo "    - Use Special:StructureSync/generate to regenerate artifacts"
echo "    - Test category-specific generation"
echo ""
echo "12. HIERARCHY VISUALIZATION:"
echo "    - Visit Special:StructureSync/hierarchy"
echo "    - Enter 'PhDStudent' to see 4-level inheritance"
echo "    - Check that inherited properties show source categories"
echo "    - Test 'GraduateStudent' for multiple inheritance (Person + LabMember)"
echo "    - Test 'PI' for complex multiple inheritance (Faculty + LabMember)"
echo "    - Verify required properties have red background, optional have green"
echo "    - Click on category/property links to navigate to their pages"
echo ""
echo "14. CATEGORY PAGE HIERARCHY:"
echo "    - Add {{#structuresync_hierarchy:}} to a Category page"
echo "    - Example: Edit Category:GraduateStudent and add the parser function"
echo "    - View the category page to see embedded hierarchy visualization"
echo ""
echo "========================================"
echo ""

