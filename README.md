# Omeka S Unique Properties

The Unique Properties module for Omeka-S allows administrators to enforce uniqueness of property values for specific properties when creating or updating items in the system. By configuring a list of properties to be unique, the system will validate the data and raise an error if the item property value already exists elsewhere among other items.


## Features

* Simple user interface to select properties that must be unique.
* Validates against existing item property values when creating or updating items.
* Raises a user-friendly error message if a duplicate value is detected.
* Maintains uniqueness of configured property values across all items.


## Installation

* Download latest release.
* Unzip and move it to omeka modules folder.
```bash
unzip -o /path/to/release.zip -d /path/to/omeka/modules
```
* Configure unique properties from admin.
