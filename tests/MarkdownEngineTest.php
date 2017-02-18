<?php

namespace allejo\stakx\tests;

use allejo\stakx\Engines\MarkdownEngine;

class MarkdownEngineTest extends \PHPUnit_Stakx_TestCase
{
    /** @var MarkdownEngine */
    private $mdEngine;

    public function setUp ()
    {
        parent::setUp();

        $this->mdEngine = MarkdownEngine::instance();
    }

    public function testHeaderIdAttr ()
    {
        $content  = '# Hello World';
        $expected = '<h1 id="hello-world">Hello World</h1>';
        $compiled = $this->mdEngine->parse($content);

        $this->assertEquals($expected, $compiled);
    }

    public function testCodeBlockWithLanguage ()
    {
        $codeBlock = <<<CODE
```php
<?php

echo "hello world";
```
CODE;
        $compiled = $this->mdEngine->parse($codeBlock);

        $this->assertContains('<code class="language-php">', $compiled);
    }

    public function testCodeBlockWithNoLanguage ()
    {
        $codeBlock = <<<CODE
```
Plain text!
```
CODE;
        $compiled = $this->mdEngine->parse($codeBlock);

        $this->assertContains('<code>', $compiled);
        $this->assertNotContains('language-', $compiled);
    }

    public function testCodeBlockWithUnsupportedLanguage ()
    {
        $this->setExpectedException(\PHPUnit_Framework_Error_Warning::class);

        $codeBlock = <<<CODE
```toast
toast("some made up");
```
CODE;
        $this->mdEngine->parse($codeBlock);
    }
}