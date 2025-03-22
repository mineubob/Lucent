[Commandline](../commandline.md)

# Progress Bar in Lucent Commandline

The `ProgressBar` component provides a customizable command-line progress bar to visualize task completion. This feature is ideal for long-running processes where you want to display progress to the user with estimated completion times.

## Table of Contents

- [Basic Concepts](#basic-concepts)
- [Features](#features)
- [Basic Usage](#basic-usage)
- [Customization Options](#customization-options)
    - [Setting the Bar Format](#setting-the-bar-format)
    - [Customizing Bar Appearance](#customizing-bar-appearance)
    - [Setting Bar Width](#setting-bar-width)
    - [Performance Optimization](#performance-optimization)
    - [Output Flushing](#output-flushing)
- [Advanced Usage](#advanced-usage)
- [Real-world Examples](#real-world-examples)
- [Performance Considerations](#performance-considerations)

## Basic Concepts

The `ProgressBar` component is part of the Lucent Commandline library. It creates a visual indicator in the terminal that updates in real-time to show the progress of an operation, along with timing information.

## Features

- Customizable appearance with different characters and styles
- Real-time elapsed time display
- Estimated time remaining (ETA) calculation
- Configurable update frequency to control performance
- Fluent interface for easy configuration

## Basic Usage

```php
use Lucent\Commandline\Components\ProgressBar;

// Create a progress bar with 100 total steps
$progress = new ProgressBar(100);

// Process your items
for ($i = 0; $i < 100; $i++) {
    // Do some work...
    usleep(50000); // Simulate work
    
    // Advance the progress bar
    $progress->advance();
}

// Finish the progress bar
$progress->finish();
```

## Customization Options

### Setting the Bar Format

You can customize the output format using placeholders:

```php
$progress = new ProgressBar(100);
$progress->setFormat('[{bar}] {percent}% - {elapsed} elapsed - {eta} remaining');
```

Available placeholders:
- `{bar}`: The actual progress bar
- `{percent}`: Percentage complete
- `{current}`: Current step
- `{total}`: Total steps
- `{elapsed}`: Elapsed time
- `{eta}`: Estimated time remaining

### Customizing Bar Appearance

You can change the characters used for the progress bar:

```php
$progress = new ProgressBar(100);
$progress->setBarCharacters(['=', ' ']); // Use = for completed and space for remaining
```

Or use other Unicode characters:

```php
$progress = new ProgressBar(100);
$progress->setBarCharacters(['▓', '░']); // Block characters
// or
$progress->setBarCharacters(['#', '-']); // ASCII style
```

### Setting Bar Width

You can change the width of the progress bar:

```php
// Create a progress bar with 50 items and 30 character width
$progress = new ProgressBar(50, 30);
```

### Performance Optimization

To prevent too frequent screen updates (which can slow down your application), you can set a minimum update interval:

```php
$progress = new ProgressBar(1000);
$progress->setUpdateInterval(0.5); // Update at most every 0.5 seconds
```

### Output Flushing

In some environments, you may need to disable output buffer flushing:

```php
$progress = new ProgressBar(100);
$progress->enableOutputFlush(false);
```

## Advanced Usage

### Updating to Specific Position

Instead of advancing by steps, you can update to a specific position:

```php
$progress = new ProgressBar(100);

// Process your items
foreach ($items as $index => $item) {
    // Do some work...
    
    // Update to a specific position
    $progress->update($index);
}

$progress->finish();
```

### Chain Configuration

You can chain configuration methods:

```php
$progress = new ProgressBar(100)
    ->setFormat('[{bar}] {percent}%')
    ->setBarCharacters(['█', '░'])
    ->setUpdateInterval(0.2);
```

### Integration with File Processing

Example of using the progress bar with file processing:

```php
$fileSize = filesize('large_file.txt');
$progress = new ProgressBar($fileSize);

$handle = fopen('large_file.txt', 'r');
$processedBytes = 0;

while (!feof($handle)) {
    $chunk = fread($handle, 8192);
    // Process chunk...
    
    $processedBytes += strlen($chunk);
    $progress->update($processedBytes);
}

fclose($handle);
$progress->finish();
```

### CSV Processing Example

```php
$csv = fopen('data.csv', 'r');
$totalRows = count(file('data.csv'));

$progress = new ProgressBar($totalRows);
$progress->setFormat('Processing CSV: [{bar}] {percent}% ({current}/{total})');

$header = fgetcsv($csv); // Skip header
$progress->advance(); // Account for header row

while (($row = fgetcsv($csv)) !== false) {
    // Process the CSV row
    processRow($row);
    
    $progress->advance();
}

fclose($csv);
$progress->finish();
```

### Batch Processing Example

```php
// Processing users in batches
$userCount = User::count();
$batchSize = 100;
$progress = new ProgressBar($userCount);
$progress->setFormat('Updating user profiles: [{bar}] {percent}% - {elapsed} elapsed');

$offset = 0;
while ($offset < $userCount) {
    $users = User::limit($batchSize)->offset($offset)->get();
    
    foreach ($users as $user) {
        // Update user profile
        $user->updateProfile();
        $progress->advance();
    }
    
    $offset += $batchSize;
}

$progress->finish();
```

## Performance Considerations

- Use a reasonable update interval (0.1-0.5 seconds) for best performance
- For very fast operations, increase the update interval to reduce overhead
- Terminal width detection can be slow on some systems; consider disabling it for highly performance-critical applications
- Only update the progress bar when necessary; excessive updates can slow down your application
- For long-running processes, consider combining the progress bar with logging for a complete record of operations

---

For more information on using Lucent's Commandline components, check the [full documentation](../README.md).