<?php

class ParsedownExtreme extends ParsedownExtra
{
    const VERSION = '1.0-beta-3';


    public function __construct()
    {
        parent::__construct();


        if (version_compare(parent::version, '0.8.0-beta-1') < 0) {
            throw new Exception('ParsedownExtreme requires a later version of Parsedown Extra');
        }

        // Blocks

        $this->BlockTypes['\\'][] = 'Latex';
        $this->BlockTypes['$'][] = 'Latex';
        $this->BlockTypes['%'][] = 'Mermaid';

        // Inline

        $this->InlineTypes['\\'][] = 'Latex';
        $this->inlineMarkerList .= '\\';


        $this->InlineTypes['='][] = 'MarkText';
        $this->inlineMarkerList .= '=';

        $this->InlineTypes['+'][] = 'InsertText';
        $this->inlineMarkerList .= '+';

        $this->InlineTypes['^'][] = 'SuperText';
        $this->inlineMarkerList .= '^';

        $this->InlineTypes['~'][] = 'SubText';

    }



    // Setters

    protected $latexMode = false;

    public function latex($input = true)
    {
        $this->latexMode = $input;

        if($input == false) {
            return $this;
        }

        $this->delimiters['block']['start'][] = '$$';
        $this->delimiters['block']['end'][] = '$$';
        $this->delimiters['block']['start'][] = '\\[';
        $this->delimiters['block']['end'][] = '\\]';

        $this->delimiters['inline']['start'][] = '\\(';
        $this->delimiters['inline']['end'][] = '\\)';

        return $this;
    }

    protected $mermaidMode = false;

    public function mermaid(bool $mode = true)
    {
        $this->mermaidMode = $mode;

        return $this;
    }

    protected $typographyMode = false;

    public function typography(bool $mode = true)
    {
        $this->typographyMode = $mode;

        return $this;
    }

    protected $superMode = false;


    public function superscript(bool $mode = true)
    {
        $this->superMode = $mode;

        return $this;
    }

    protected $markMode = true;

    public function mark(bool $mode = true)
    {
        $this->markMode = $mode;

        return $this;
    }

    protected $insertMode = true;

    public function insert(bool $insertMode = true)
    {
        $this->insertMode = $insertMode;

        return $this;
    }

    protected $mediaMode = true;

    public function media(bool $mediaMode = true)
    {
        $this->mediaMode = $mediaMode;

        return $this;
    }







    // -------------------------------------------------------------------------
    // -----------------------         Inline         --------------------------
    // -------------------------------------------------------------------------


    #
    # Typography Replacer
    # --------------------------------------------------------------------------

    protected function linesElements(array $Lines)
    {
        $Elements = array();
        $CurrentBlock = null;

        foreach ($Lines as $Line) {
            if (chop($Line) === '') {
                if (isset($CurrentBlock)) {
                    $CurrentBlock['interrupted'] = (
                        isset($CurrentBlock['interrupted'])
                        ? $CurrentBlock['interrupted'] + 1 : 1
                    );
                }

                continue;
            }

            while (($beforeTab = strstr($Line, "\t", true)) !== false) {
                $shortage = 4 - mb_strlen($beforeTab, 'utf-8') % 4;

                $Line = $beforeTab.str_repeat(' ', $shortage).substr($Line, strlen($beforeTab) + 1);
            }

            $indent = strspn($Line, ' ');

            $text = $indent > 0 ? substr($Line, $indent) : $Line;

            // ~

            $Line = array('body' => $Line, 'indent' => $indent, 'text' => $text);

            // ~

            if (isset($CurrentBlock['continuable'])) {
                $methodName = 'block' . $CurrentBlock['type'] . 'Continue';
                $Block = $this->$methodName($Line, $CurrentBlock);

                if (isset($Block)) {
                    $CurrentBlock = $Block;

                    continue;
                } else {
                    if ($this->isBlockCompletable($CurrentBlock['type'])) {
                        $methodName = 'block' . $CurrentBlock['type'] . 'Complete';
                        $CurrentBlock = $this->$methodName($CurrentBlock);
                    }
                }
            }

            // ~

            $marker = $text[0];

            // ~

            $BlockTypes = $this->unmarkedBlockTypes;

            if (isset($this->BlockTypes[$marker])) {
                foreach ($this->BlockTypes[$marker] as $BlockType) {
                    $BlockTypes []= $BlockType;
                }
            }

            // ~

            foreach ($BlockTypes as $BlockType) {
                $Block = $this->{"block$BlockType"}($Line, $CurrentBlock);

                if (isset($Block)) {
                    $Block['type'] = $BlockType;

                    if (! isset($Block['identified'])) {
                        if (isset($CurrentBlock)) {
                            $Elements[] = $this->extractElement($CurrentBlock);
                        }

                        $Block['identified'] = true;
                    }

                    if ($this->isBlockContinuable($BlockType)) {
                        $Block['continuable'] = true;
                    }

                    $CurrentBlock = $Block;

                    continue 2;
                }
            }

            // ~
            if (isset($CurrentBlock) and $CurrentBlock['type'] === 'Paragraph') {
                $Block = $this->paragraphContinue($Line, $CurrentBlock);
            }

            if (isset($Block)) {
                $CurrentBlock = $Block;
            } else {
                if (isset($CurrentBlock)) {
                    $Elements[] = $this->extractElement($CurrentBlock);
                }

                if ($this->typographyMode and $Block['latex'] != true) {
                    $typographicReplace = array(
                        '(c)' => '&copy;',
                        '(C)' => '&copy;',
                        '(r)' => '&reg;',
                        '(R)' => '&reg;',
                        '(tm)' => '&trade;',
                        '(TM)' => '&trade;'
                    );
                    $Line = $this->strReplaceAssoc($typographicReplace, $Line);
                }

                $CurrentBlock = $this->paragraph($Line);

                $CurrentBlock['identified'] = true;
            }
        }

        // ~

        if (isset($CurrentBlock['continuable']) and $this->isBlockCompletable($CurrentBlock['type'])) {
            $methodName = 'block' . $CurrentBlock['type'] . 'Complete';
            $CurrentBlock = $this->$methodName($CurrentBlock);
        }

        // ~

        if (isset($CurrentBlock)) {
            $Elements[] = $this->extractElement($CurrentBlock);
        }

        // ~

        return $Elements;
    }



    #
    # Mark
    # --------------------------------------------------------------------------

    protected function inlineMarkText($excerpt)
    {
        if (!$this->markMode) {
            return;
        }

        if (preg_match('/^(==)([\s\S]*?)(==)/', $excerpt['text'], $matches)) {
            return array(
                // How many characters to advance the Parsedown's
                // cursor after being done processing this tag.
                'extent' => strlen($matches[0]),
                'element' => array(
                    'name' => 'mark',
                    'text' => $matches[2]
                ),
            );
        }
    }

    #
    # Inline Latex
    # --------------------------------------------------------------------------

    protected function inlineLatex($excerpt)
    {
        if (!$this->latexMode) {
            return;
        }

        foreach($this->delimiters['inline']['start'] as $key => $v) {
            $start = preg_quote($this->delimiters['inline']['start'][$key], '/');
            $end = preg_quote($this->delimiters['inline']['end'][$key], '/');

            if (preg_match('/^(?<!'.$start.')(?:('.$start.'))(?:(.*(?0)?.*)(?<!'.$start.')(?:(?(1)'.$end.')))/sU', $excerpt['text'], $matches)) {
                $text = $matches[0];
                $text = preg_replace('/[ ]*+\n/', ' ', $text);

                return array(
                    'extent' => strlen($matches[0]),
                    'element' => array(
                        'text' => $text,
                    ),
                );
            }
        }
    }

    // BUG: Not work with secound inline option
    protected function inlineEscapeSequence($excerpt)
    {
        if(isset($excerpt['text'][1]) and in_array($excerpt['text'][1], $this->specialCharacters) and !preg_match('/\\\\\(.*\\\\\)/', $excerpt['text'])) {

            return array(
                'element' => array(
                    'rawHtml' => $excerpt['text'][1],
                    // 'name' => 'span',
                    // 'attributes' => array(
                    //     'style' => 'background-color: red;'
                    // )
                ),
                'extent' => 2,
            );

        }
    }


    // Media
    // Override inlineImage
    protected function inlineImage($excerpt)
    {
        if (!$this->mediaMode) {
            return;
        }
        if (!isset($excerpt['text'][1]) or $excerpt['text'][1] !== '[') {
            return;
        }

        $excerpt['text'] = substr($excerpt['text'], 1);

        $link = $this->inlineLink($excerpt);

        if ($link === null) {
            return;
        }


        $needles = array(
            'video' => [
                'youtube',
                'vimeo',
                'dailymotion',
                'metacafe',
            ],
            'audio' => [
                'spotify',
                'soundcloud'
            ]
        );
        $sourceType = '';
        $sourceName = '';
        foreach ($needles as $type => $group) {
            foreach ($group as $name) {
                if (strpos($link['element']['attributes']['href'], $name) !== false) {
                    $sourceType = $type;
                    $sourceName = $name;
                }
            }
        }

        // Set HTML element
        $element = 'iframe';

        switch ([$sourceType, $sourceName]) {
            // Video
            case ['video', 'youtube']:
                $attributes = array(
                    'src' => preg_replace('/.*\?v=([^\&\]]*).*/', 'https://www.youtube.com/embed/$1', $link['element']['attributes']['href']),
                );
                break;
            case ['video', 'vimeo']:
                $attributes = array(
                    'src' => preg_replace('/(?:https?:\/\/(?:[\w]{3}\.|player\.)*vimeo\.com(?:[\/\w:]*(?:\/videos)?)?\/([0-9]+)[^\s]*)/', 'https://player.vimeo.com/video/$1', $link['element']['attributes']['href']),
                );
                break;
            // NOTE: Check up on this
            case ['video', 'dailymotion']:
                $attributes = array(
                    'src' => $link['element']['attributes']['href'],
                );
                break;
            case ['video', 'metacafe']:
                $attributes = array(
                    'src' => preg_replace('/.+(?:watch|embed)\/(.+)/', 'https://www.metacafe.com/embed/$1', $link['element']['attributes']['href']),
                );
                break;
            // Audio
            case ['audio', 'spotify']:
                $uniqueId = $this->oembed('https://embed.spotify.com/oembed?format=json&url='.$link['element']['attributes']['href'], '/.*((?:track|artist|ablum|playlist)\\\\\/(?:[^\\\\\/]*)).+/');
                if (empty($uniqueId)) {
                    return;
                }
                $attributes = array(
                    'height' => 300,
                    'height' => 80,
                    'src' => "https://open.spotify.com/embed/".preg_replace('/\\\/', '', $uniqueId),
                );
                break;
            case ['audio', 'soundcloud']:
                $uniqueId = $this->oembed('http://soundcloud.com/oembed?format=json&url='.$link['element']['attributes']['href'], '/.+Ftracks%2F([^\\\]*)\\\.+/');

                $attributes = array(
                    'src' => "https://w.soundcloud.com/player/?url=https%3A//api.soundcloud.com/tracks/{$uniqueId}&color=%23ff5500&auto_play=true&hide_related=false&show_comments=true&show_user=true&show_reposts=false&show_teaser=true&visual=true",
                );
                break;
            default:

                // URL regex
                $reg_exUrl = "/(http|https|ftp|ftps)/";

                // Check if URL
                if (preg_match($reg_exUrl, $link['element']['attributes']['href'])) {
                    // URL
                    $headers = get_headers($link['element']['attributes']['href'], 1);
                    $type = preg_replace('/^([^\/]+)(?:\/.+)/', '$1', $headers['Content-Type']);

                    switch ($type) {
                        case 'video':
                            $element = 'video';
                            break;
                        case 'audio':
                            $element = 'audio';
                            break;
                        default:
                            $element = 'img';
                    }
                } else {
                    // Local
                    try {
                        $type = preg_replace('/^([^\/]+)(?:\/.+)/', '$1', mime_content_type($link['element']['attributes']['href']));

                        switch ($type) {
                            case 'video':
                                $element = 'video';
                                break;
                            case 'audio':
                                $element = 'audio';
                                break;
                            default:
                                $element = 'img';
                        }
                    } catch (Exception $e) {
                        echo 'Caught exception: ',  $e->getMessage(), "\n";
                    }
                }
                $attributes = array(
                    'src' => $link['element']['attributes']['href'],
                );
        }

        if (!empty($link['element']['handler']['argument'])) {
            $attributes += ['alt' => $link['element']['handler']['argument']];
        }

        if ($type == 'video') {
            // default settings
            $attributes += [
                'frameborder' => '0',
                'allowfullscreen' => '',
                'allow' => 'autoplay; encrypted-media',
                'controls' => ''
            ];
        } elseif ($type == 'audio') {
            // default settings
            $attributes += [
                'frameborder' => '0',
                'allow' => 'autoplay; encrypted-media',
                'controls' => ''
            ];
        }

        $inline = array(
            'extent' => $link['extent'] + 1,
            'element' => array(
                'text' => '',
                'name' => $element,
                'attributes' => $attributes,
                'autobreak' => true,
            ),
        );

        $inline['element']['attributes'] += $link['element']['attributes'];

        unset($inline['element']['attributes']['href']);


        return $inline;
    }


    #
    # Superscript
    # --------------------------------------------------------------------------

    protected function inlineSuperText($excerpt)
    {
        if (!$this->superMode) {
            return;
        }

        if (preg_match('/(?:\^(?!\^)([^\^ ]*)\^(?!\^))/', $excerpt['text'], $matches)) {
            return array(

                // How many characters to advance the Parsedown's
                // cursor after being done processing this tag.
                'extent' => strlen($matches[0]),
                'element' => array(
                    'name' => 'sup',
                    'text' => $matches[1],
                    'function' => 'lineElements'
                ),

            );
        }
    }

    #
    # Subscript
    # --------------------------------------------------------------------------

    protected function inlineSubText($excerpt)
    {
        if (!$this->superMode) {
            return;
        }

        if (preg_match('/(?:~(?!~)([^~ ]*)~(?!~))/', $excerpt['text'], $matches)) {
            return array(

                // How many characters to advance the Parsedown's
                // cursor after being done processing this tag.
                'extent' => strlen($matches[0]),
                'element' => array(
                    'name' => 'sub',
                    'text' => $matches[1],
                    'function' => 'lineElements'
                ),

            );
        }
    }

    #
    # Insert
    # --------------------------------------------------------------------------

    protected function inlineInsertText($excerpt)
    {
        if (!$this->insertMode) {
            return;
        }

        if (preg_match('/^(\+\+)([\s\S]*?)(\+\+)/', $excerpt['text'], $matches)) {
            return array(

                // How many characters to advance the Parsedown's
                // cursor after being done processing this tag.
                'extent' => strlen($matches[0]),
                'element' => array(
                    'name' => 'ins',
                    'text' => $matches[2]
                ),

            );
        }
    }

    // -------------------------------------------------------------------------
    // -----------------------         Blocks         --------------------------
    // -------------------------------------------------------------------------

    // Header

    protected function blockHeader($Line)
    {
        $Block = parent::blockHeader($Line);

        if (preg_match('/[ #]*{('.$this->regexAttribute.'+)}[ ]*$/', $Block['element']['handler']['argument'], $matches, PREG_OFFSET_CAPTURE)) {
            $attributeString = $matches[1][0];

            $Block['element']['attributes'] = $this->parseAttributeData($attributeString);

            $Block['element']['handler']['argument'] = substr($Block['element']['handler']['argument'], 0, $matches[0][1]);
        }

        if (!isset($Block['element']['attributes']['id']) && isset($Block['element']['handler']['argument'])) {
            $Block['element']['attributes']['id'] = preg_replace('/\s+/', '-', $this->hyphenize($Block['element']['handler']['argument']));
        }

        $link = "#".$Block['element']['attributes']['id'];

        $Block['element']['handler']['argument'] = $Block['element']['handler']['argument']."<a class='heading-link' href='{$link}'><i class='fal fa-link'></i></a>";

        return $Block;
    }


    #
    # Setext

    protected function blockSetextHeader($Line, array $Block = null)
    {
        $Block = parent::blockSetextHeader($Line, $Block);

        if (preg_match('/[ ]*{('.$this->regexAttribute.'+)}[ ]*$/', $Block['element']['handler']['argument'], $matches, PREG_OFFSET_CAPTURE)) {
            $attributeString = $matches[1][0];

            $Block['element']['attributes'] = $this->parseAttributeData($attributeString);

            $Block['element']['handler']['argument'] = substr($Block['element']['handler']['argument'], 0, $matches[0][1]);
        }

        if (!isset($Block['element']['attributes']['id']) && isset($Block['element']['handler']['argument'])) {
            $Block['element']['attributes']['id'] = preg_replace('/\s+/', '-', $this->hyphenize($Block['element']['handler']['argument']));
        }

        return $Block;
    }


    #
    # Block Latex
    # --------------------------------------------------------------------------

    protected function blockLatex($Line, $Block = null)
    {
        if (!$this->latexMode) {
            return;
        }


        $Block = array(
            'element' => array(
                'text' => '',
            )
        );

        foreach($this->delimiters['block']['start'] as $key => $v) {
            $start = preg_quote($this->delimiters['block']['start'][$key], '/');
            if(preg_match('/^('.$start.')/', $Line['text']))
            {
                return $Block;
            }
        }

        $Block['element']['text'] .= "\n" . $Line['body'];

        return $Block;
    }

    protected function blockLatexContinue($Line, $Block)
    {

        if (isset($Block['complete']))
        {
            return;
        }

        if (isset($Block['interrupted']))
        {
            $Block['element']['text'] .= str_repeat("\n", $Block['interrupted']);
            unset($Block['interrupted']);
        }

        foreach($this->delimiters['block']['start'] as $key => $v) {
            $start = preg_quote($this->delimiters['block']['start'][$key], '/');
            $end = preg_quote($this->delimiters['block']['end'][$key], '/');
            // Check for end of the block.
            if (preg_match('/'.$end.'/', $Line['text']))
            {
                $Block['element']['text'] = $this->delimiters['block']['start'][$key].$Block['element']['text'].$this->delimiters['block']['end'][$key];

                $Block['complete'] = true;

                return $Block;
            }
        }

        $Block['element']['text'] .= "\n" . $Line['body'];

        $Block['latex'] = true;

        return $Block;
    }

    protected function blockBoldTextComplete($Block)
    {
        return $Block;
    }

    #
    # Block Mermaid
    # --------------------------------------------------------------------------
    protected function blockMermaid($Line)
    {
        if (!$this->mermaidMode) {
            return;
        }

        $marker = $Line['text'][0];

        $openerLength = strspn($Line['text'], $marker);

        if ($openerLength < 2) {
            return;
        }

        $this->infostring = strtolower(trim(substr($Line['text'], $openerLength), "\t "));

        if (strpos($this->infostring, '%') !== false) {
            return;
        }


        $Element = array(
            'text' => ''
        );

        $Block = array(
            'char' => $marker,
            'openerLength' => $openerLength,
            'element' => array(
                'element' => $Element,
                'name' => 'div',
                'attributes' => array(
                    'class' => 'mermaid'
                ),
            )
        );

        return $Block;
    }

    protected function blockMermaidContinue($Line, $Block)
    {
        if (!$this->mermaidMode) {
            return;
        }

        if (isset($Block['complete'])) {
            return;
        }

        // A blank newline has occurred.
        if (isset($Block['interrupted'])) {
            $Block['element']['text'] .= "\n";
            unset($Block['interrupted']);
        }

        // Check for end of the block.
        if (($len = strspn($Line['text'], $Block['char'])) >= $Block['openerLength'] and chop(substr($Line['text'], $len), ' ') === '') {
            $Block['element']['element']['text'] = substr($Block['element']['element']['text'], 1);

            $Block['complete'] = true;

            return $Block;
        }

        $Block['element']['element']['text'] .= "\n" . $Line['body'];

        return $Block;
    }

    protected function blockMermaidComplete($Block)
    {
        return $Block;
    }


    #
    # List with support for checkbox
    # --------------------------------------------------------------------------

    protected function blockList($Line, array $CurrentBlock = null)
    {
        list($name, $pattern) = $Line['text'][0] <= '-' ? array('ul', '[*+-]') : array('ol', '[0-9]{1,9}+[.\)]');

        if (preg_match('/^('.$pattern.'([ ]++|$))(.*+)/', $Line['text'], $matches)) {
            $contentIndent = strlen($matches[2]);

            if ($contentIndent >= 5) {
                $contentIndent -= 1;
                $matches[1] = substr($matches[1], 0, -$contentIndent);
                $matches[3] = str_repeat(' ', $contentIndent) . $matches[3];
            } elseif ($contentIndent === 0) {
                $matches[1] .= ' ';
            }

            $markerWithoutWhitespace = strstr($matches[1], ' ', true);

            $Block = array(
                'indent' => $Line['indent'],
                'pattern' => $pattern,
                'data' => array(
                    'type' => $name,
                    'marker' => $matches[1],
                    'markerType' => ($name === 'ul' ? $markerWithoutWhitespace : substr($markerWithoutWhitespace, -1)),
                ),
                'element' => array(
                    'name' => $name,
                    'elements' => array(),
                ),
            );
            $Block['data']['markerTypeRegex'] = preg_quote($Block['data']['markerType'], '/');

            if ($name === 'ol') {
                $listStart = ltrim(strstr($matches[1], $Block['data']['markerType'], true), '0') ?: '0';

                if ($listStart !== '1') {
                    if (
                        isset($CurrentBlock)
                        and $CurrentBlock['type'] === 'Paragraph'
                        and ! isset($CurrentBlock['interrupted'])
                    ) {
                        return;
                    }

                    $Block['element']['attributes'] = array('start' => $listStart);
                }
            }

            $this->checkbox($matches[3], $attributes);

            $Block['li'] = array(
                'name' => 'li',
                'handler' => array(
                    'function' => 'li',
                    'argument' => !empty($matches[3]) ? array($matches[3]) : array(),
                    'destination' => 'elements'
                )
            );

            $attributes && $Block['li']['attributes'] = $attributes;

            $Block['element']['elements'] []= & $Block['li'];

            return $Block;
        }
    }



    protected function blockListContinue($Line, array $Block)
    {
        if (isset($Block['interrupted']) and empty($Block['li']['handler']['argument'])) {
            return null;
        }

        $requiredIndent = ($Block['indent'] + strlen($Block['data']['marker']));

        if ($Line['indent'] < $requiredIndent
            and (
                (
                    $Block['data']['type'] === 'ol'
                    and preg_match('/^[0-9]++'.$Block['data']['markerTypeRegex'].'(?:[ ]++(.*)|$)/', $Line['text'], $matches)
                ) or (
                    $Block['data']['type'] === 'ul'
                    and preg_match('/^'.$Block['data']['markerTypeRegex'].'(?:[ ]++(.*)|$)/', $Line['text'], $matches)
                )
            )
        ) {
            if (isset($Block['interrupted'])) {
                $Block['li']['handler']['argument'] []= '';

                $Block['loose'] = true;

                unset($Block['interrupted']);
            }

            unset($Block['li']);

            $text = isset($matches[1]) ? $matches[1] : '';

            $this->checkbox($text, $attributes);


            $Block['indent'] = $Line['indent'];

            $Block['li'] = array(
                'name' => 'li',
                'handler' => array(
                    'function' => 'li',
                    'argument' => array($text),
                    'destination' => 'elements'
                )
            );
            $attributes && $Block['li']['attributes'] = $attributes;
            $Block['element']['elements'] []= & $Block['li'];

            return $Block;
        } elseif ($Line['indent'] < $requiredIndent and $this->blockList($Line)) {
            return null;
        }

        if ($Line['text'][0] === '[' and $this->blockReference($Line)) {
            return $Block;
        }

        if ($Line['indent'] >= $requiredIndent) {
            if (isset($Block['interrupted'])) {
                $Block['li']['handler']['argument'] []= '';

                $Block['loose'] = true;

                unset($Block['interrupted']);
            }

            $text = substr($Line['body'], $requiredIndent);

            $Block['li']['handler']['argument'] []= $text;

            return $Block;
        }

        if (! isset($Block['interrupted'])) {
            $text = preg_replace('/^[ ]{0,'.$requiredIndent.'}+/', '', $Line['body']);

            $Block['li']['handler']['argument'] []= $text;

            return $Block;
        }
    }

    protected function blockListComplete(array $Block)
    {
        if (isset($Block['loose'])) {
            foreach ($Block['element']['elements'] as &$li) {
                if (end($li['handler']['argument']) !== '') {
                    $li['handler']['argument'] []= '';
                }
            }
        }

        return $Block;
    }


    // -------------------------------------------------------------------------
    // -----------------------         Helpers        --------------------------
    // -------------------------------------------------------------------------


    private function oembed($link, $regxr)
    {
        if ($data = @file_get_contents($link)) {
            return preg_replace($regxr, '$1', $data);
        }

        return;
    }

    private function hyphenize($string)
    {
        $dict = array(
            "I'm"      => "I am",
            "thier"    => "their",
            // Add your own replacements here
        );
        return strtolower(
            preg_replace(
              array( '#[\\s-]+#', '#[^A-Za-z0-9\. -]+#' ),
              array( '-', '' ),
              // the full cleanString() can be downloaded from http://www.unexpectedit.com/php/php-clean-string-of-utf8-chars-convert-to-similar-ascii-char
              $this->cleanString(
                  str_replace( // preg_replace can be used to support more complicated replacements
                      array_keys($dict),
                      array_values($dict),
                      urldecode($string)
                  )
              )
            )
        );
    }

    private function cleanString($text)
    {
        $utf8 = array(
            '/[áàâãªä]/u'   =>   'a',
            '/[ÁÀÂÃÄ]/u'    =>   'A',
            '/[ÍÌÎÏ]/u'     =>   'I',
            '/[íìîï]/u'     =>   'i',
            '/[éèêë]/u'     =>   'e',
            '/[ÉÈÊË]/u'     =>   'E',
            '/[óòôõºö]/u'   =>   'o',
            '/[ÓÒÔÕÖ]/u'    =>   'O',
            '/[úùûü]/u'     =>   'u',
            '/[ÚÙÛÜ]/u'     =>   'U',
            '/ç/'           =>   'c',
            '/Ç/'           =>   'C',
            '/ñ/'           =>   'n',
            '/Ñ/'           =>   'N',
            '/–/'           =>   '-', // UTF-8 hyphen to "normal" hyphen
            '/[’‘‹›‚]/u'    =>   ' ', // Literally a single quote
            '/[“”«»„]/u'    =>   ' ', // Double quote
            '/ /'           =>   ' ', // nonbreaking space (equiv. to 0x160)
        );
        return preg_replace(array_keys($utf8), array_values($utf8), $text);
    }

    // Checkbox
    protected function checkbox(&$text, &$attributes)
    {
        if (strpos($text, '[x]') !== false || strpos($text, '[ ]') !== false) {
            $attributes = array("style" => "list-style: none;");
            $text = str_replace(array('[x]', '[ ]'), array(
                '<input type="checkbox" checked="true" disabled="true">',
                '<input type="checkbox" disabled="true">',
            ), $text);
        }
    }

    // ReplaceAssoc
    protected function strReplaceAssoc(array $replace, $subject)
    {
        return str_replace(array_keys($replace), array_values($replace), $subject);
    }



    // -------------------------------------------------------------------------

    protected function seems_utf8($str)
    {
        $this->mbstring_binary_safe_encoding();
        $length = strlen($str);
        $this->reset_mbstring_encoding();
        for ($i=0; $i < $length; $i++) {
            $c = ord($str[$i]);
            if ($c < 0x80) {
                $n = 0;
            } // 0bbbbbbb
            elseif (($c & 0xE0) == 0xC0) {
                $n=1;
            } // 110bbbbb
            elseif (($c & 0xF0) == 0xE0) {
                $n=2;
            } // 1110bbbb
            elseif (($c & 0xF8) == 0xF0) {
                $n=3;
            } // 11110bbb
            elseif (($c & 0xFC) == 0xF8) {
                $n=4;
            } // 111110bb
            elseif (($c & 0xFE) == 0xFC) {
                $n=5;
            } // 1111110b
            else {
                return false;
            } // Does not match any model
            for ($j=0; $j<$n; $j++) { // n bytes matching 10bbbbbb follow ?
                if ((++$i == $length) || ((ord($str[$i]) & 0xC0) != 0x80)) {
                    return false;
                }
            }
        }
        return true;
    }

    protected function reset_mbstring_encoding()
    {
        $this->mbstring_binary_safe_encoding(true);
    }

    protected function mbstring_binary_safe_encoding($reset = false)
    {
        static $encodings = array();
        static $overloaded = null;

        if (is_null($overloaded)) {
            $overloaded = function_exists('mb_internal_encoding') && (ini_get('mbstring.func_overload') & 2);
        }

        if (false === $overloaded) {
            return;
        }

        if (! $reset) {
            $encoding = mb_internal_encoding();
            array_push($encodings, $encoding);
            mb_internal_encoding('ISO-8859-1');
        }

        if ($reset && $encodings) {
            $encoding = array_pop($encodings);
            mb_internal_encoding($encoding);
        }
    }
}
