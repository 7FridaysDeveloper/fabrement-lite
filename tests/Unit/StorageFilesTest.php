<?php

namespace Fabrement\Tests;

use Fabrement\Modules\Storage\StorageFiles;
use PHPUnit\Framework\TestCase;

final class StorageFilesTest extends TestCase {
    private string $root;
    private \ReflectionProperty $filesystem;
    private ?\WP_Filesystem_Direct $previous_filesystem;

    protected function setUp(): void {
        $this->root = sys_get_temp_dir() . '/fabrement-storage-api-tests-' . getmypid();
        wp_mkdir_p( $this->root );
        require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
        $this->filesystem = new \ReflectionProperty( StorageFiles::class, 'filesystem' );
        $this->filesystem->setAccessible( true );
        $this->previous_filesystem = $this->filesystem->getValue();
        $this->filesystem->setValue( null, new \WP_Filesystem_Direct( null ) );
    }

    protected function tearDown(): void {
        $this->filesystem->setValue( null, $this->previous_filesystem );
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator( $this->root, \FilesystemIterator::SKIP_DOTS ),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ( $items as $item ) {
            $item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
        }
        rmdir( $this->root );
    }

    public function test_rejected_write_preserves_the_working_file_and_removes_its_temporary_file(): void {
        $file = $this->root . '/render.php';
        file_put_contents( $file, '<?php // Working version.' );

        self::assertFalse( StorageFiles::write( $file, 'invalid replacement', static fn(): bool => false ) );
        self::assertStringEqualsFile( $file, '<?php // Working version.' );
        self::assertSame( [], $this->temporary_files() );
        self::assertTrue( StorageFiles::delete( $file ) );
        self::assertFileDoesNotExist( $file );
    }

    private function temporary_files(): array {
        return glob( $this->root . '/.fabrement-write-*.tmp' ) ?: [];
    }

    public function test_the_old_file_remains_readable_until_the_validated_replacement_is_published(): void {
        $file = $this->root . '/render.php';
        $contents = "<?php // Replacement: українська\n" . str_repeat( "a\0b", 4096 );
        file_put_contents( $file, 'old contents' );
        $previous = $GLOBALS['wp_filesystem'] ?? null;
        $sentinel = new \stdClass();
        $GLOBALS['wp_filesystem'] = $sentinel;
        try {
            self::assertTrue( StorageFiles::write( $file, $contents, function ( string $written ) use ( $file, $contents ): bool {
                self::assertStringEqualsFile( $file, 'old contents' );
                self::assertSame( $contents, $written );
                self::assertCount( 1, $this->temporary_files() );
                return true;
            } ) );
            self::assertSame( $sentinel, $GLOBALS['wp_filesystem'] );
        } finally {
            if ( $previous === null ) {
                unset( $GLOBALS['wp_filesystem'] );
            } else {
                $GLOBALS['wp_filesystem'] = $previous;
            }
        }
        self::assertStringEqualsFile( $file, $contents );
        self::assertSame( [], $this->temporary_files() );
        self::assertTrue( StorageFiles::write( $file, '' ) );
        self::assertStringEqualsFile( $file, '' );
    }

    /** @dataProvider io_failures */
    public function test_io_failures_preserve_the_old_file_and_clean_up( string $failure ): void {
        $this->filesystem->setValue( null, new class( $failure ) extends \WP_Filesystem_Direct {
            private string $failure;

            public function __construct( string $failure ) {
                parent::__construct( null );
                $this->failure = $failure;
            }

            public function put_contents( $file, $contents, $mode = false ) {
                if ( in_array( $this->failure, [ 'short_false', 'short_true' ], true ) ) {
                    parent::put_contents( $file, substr( $contents, 0, 4 ), $mode );
                    return $this->failure === 'short_true';
                }
                if ( $this->failure === 'corrupt' ) {
                    $contents = str_repeat( '?', strlen( $contents ) );
                }
                return parent::put_contents( $file, $contents, $mode );
            }

            public function get_contents( $file ) {
                return $this->failure === 'read' ? false : parent::get_contents( $file );
            }

            public function chmod( $file, $mode = false, $recursive = false ) {
                return $this->failure === 'permissions' ? false : parent::chmod( $file, $mode, $recursive );
            }
        } );
        $file = $this->root . '/render.php';
        file_put_contents( $file, 'old contents' );

        self::assertFalse( StorageFiles::write( $file, 'complete replacement' ) );
        self::assertStringEqualsFile( $file, 'old contents' );
        self::assertSame( [], $this->temporary_files() );
    }

    public static function io_failures(): array {
        return [ [ 'short_false' ], [ 'short_true' ], [ 'corrupt' ], [ 'read' ], [ 'permissions' ] ];
    }

    public function test_failed_rename_does_not_delete_the_destination(): void {
        $path = $this->root . '/occupied';
        wp_mkdir_p( $path );
        file_put_contents( $path . '/keep.txt', 'keep' );

        self::assertFalse( StorageFiles::write( $path, 'cannot replace a directory' ) );
        self::assertStringEqualsFile( $path . '/keep.txt', 'keep' );
        self::assertSame( [], $this->temporary_files() );
    }

    public function test_verifier_exceptions_clean_up_without_changing_the_old_file(): void {
        $file = $this->root . '/render.php';
        file_put_contents( $file, 'old contents' );
        try {
            StorageFiles::write( $file, 'replacement', static function (): bool {
                throw new \RuntimeException( 'Validation failed.' );
            } );
            self::fail( 'Expected the verifier exception.' );
        } catch ( \RuntimeException $e ) {
            self::assertSame( 'Validation failed.', $e->getMessage() );
        }
        self::assertStringEqualsFile( $file, 'old contents' );
        self::assertSame( [], $this->temporary_files() );
    }

    public function test_overlapping_writers_use_different_temporary_files(): void {
        $file = $this->root . '/render.php';
        file_put_contents( $file, 'old contents' );

        self::assertTrue( StorageFiles::write( $file, 'outer write', function () use ( $file ): bool {
            $outer_temp = $this->temporary_files();
            self::assertCount( 1, $outer_temp );
            self::assertTrue( StorageFiles::write( $file, 'inner write', function () use ( $file ): bool {
                self::assertCount( 2, $this->temporary_files() );
                self::assertStringEqualsFile( $file, 'old contents' );
                return true;
            } ) );
            self::assertStringEqualsFile( $file, 'inner write' );
            self::assertSame( $outer_temp, $this->temporary_files() );
            return true;
        } ) );
        self::assertStringEqualsFile( $file, 'outer write' );
        self::assertSame( [], $this->temporary_files() );
    }
}
