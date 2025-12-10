<?php
namespace PerformanceOptimisation\Tests\Unit;

use PerformanceOptimisation\Utils\FileSystemUtil;
use PHPUnit\Framework\TestCase;

class FileSystemUtilReproTest extends TestCase {
    /**
     * Test that 1024 bytes is formatted as 1 KB.
     */
    public function test_format_file_size_1024() {
        // Before fix: returns "1024 B"
        // After fix: returns "1 KB"
        $this->assertEquals('1 KB', FileSystemUtil::formatFileSize(1024));
    }
}
