<?php

use PHPUnit\Framework\TestCase;
use Sabre\VObject;

// Set up globals that might be expected
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

class LogicTest extends TestCase
{
    private $cacheFile;
    private $etagFile;

    protected function setUp(): void
    {
        $this->cacheFile = dirname(__DIR__) . '/cache/offer.ics';
        $this->etagFile = dirname(__DIR__) . '/cache/offer.etag';
        
        // Backup if needed, or just clear them
        @unlink($this->cacheFile);
        @unlink($this->etagFile);
    }
    
    protected function tearDown(): void
    {
        @unlink($this->cacheFile);
        @unlink($this->etagFile);
    }

    public function testGetSlotsExtractsFromIcs()
    {
        // Create a dummy ICS that represents a free slot
        // Make it in the future to pass horizon checks (but within DEFAULT_HORIZON_DAYS)
        $future = (new DateTime())->modify('+2 days');
        $futureStart = $future->format('Ymd\THis');
        $futureEnd = (clone $future)->modify('+1 hour')->format('Ymd\THis');
        
        $icsData = <<<ICS
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//Sabre//Sabre VObject 4.5.3//EN
CALSCALE:GREGORIAN
BEGIN:VEVENT
UID:12345-test-uid
DTSTART;TZID=Europe/Zurich:{$futureStart}
DTEND;TZID=Europe/Zurich:{$futureEnd}
SUMMARY:Meeting
LOCATION:Test City
END:VEVENT
END:VCALENDAR
ICS;
        file_put_contents($this->cacheFile, $icsData);
        // Ensure cache is considered fresh
        touch($this->cacheFile);
        
        $slots = get_slots();
        
        if (empty($slots)) {
            $this->fail("No slots returned. Rules might have filtered it out.");
        }
        
        $this->assertCount(1, $slots);
        $this->assertEquals('12345-test-uid', $slots[0]['uid']);
        $this->assertEquals('Meeting', $slots[0]['summary']);
        $this->assertEquals('Test City', $slots[0]['location']);
    }
}

