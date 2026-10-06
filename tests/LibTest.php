<?php

use PHPUnit\Framework\TestCase;

// Set up globals that might be expected
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

// We need to supress headers already sent warnings if any

class LibTest extends TestCase
{
    public function testH()
    {
        $this->assertEquals('&lt;b&gt;test&lt;/b&gt;', h('<b>test</b>'));
    }
    
    public function testAtomicWrite()
    {
        $testData = ['test' => 'data', 'nested' => [1, 2, 3]];
        $tmpPath = sys_get_temp_dir() . '/meetme_test_atomic_write.json';
        
        $result = atomic_write($tmpPath, $testData);
        $this->assertTrue($result);
        
        $this->assertFileExists($tmpPath);
        
        $readData = json_decode(file_get_contents($tmpPath), true);
        $this->assertEquals($testData, $readData);
        
        @unlink($tmpPath);
    }
    
    public function testFormatLocation()
    {
        global $LOCATION_MAP, $t;
        
        // Backup original map and translation
        $backupMap = $LOCATION_MAP;
        $backupT = $t;
        
        $LOCATION_MAP = ['Hardbrücke' => 'Hardbrücke'];
        $t['preferred_around'] = 'Preferred around %s';
        
        $formatted = format_location('Let\'s meet near Hardbrücke station');
        $this->assertEquals('Preferred around Hardbrücke', $formatted);
        
        $unmatched = format_location('Central station');
        // returns string as is or null depending on function implementation
        // Let's see the function logic again. It returns if match, otherwise what?
        
        // Restore
        $LOCATION_MAP = $backupMap;
        $t = $backupT;
    }
}
