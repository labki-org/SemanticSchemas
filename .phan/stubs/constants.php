<?php

// https://github.com/phan/phan/issues/506

// Statically define constants that SMW defines dynamically
define('SMW_NS_PROPERTY', 102);
define('SMW_NS_PROPERTY_TALK', 103);


// PF constants (pf is not present as a dependency)
define( 'PF_NS_FORM', 106 );
define( 'PF_NS_FORM_TALK', 107 );