<?php

namespace Eduardokum\LaravelBoleto\Boleto\Render;

use FPDF;
use Exception;

abstract class AbstractPdf extends FPDF
{
    // INCLUDE JS
    protected $javascript;

    protected $n_js;

    protected $angle = 0;

    // PAGE GROUP
    protected $NewPageGroup; // variable indicating whether a new group was requested

    protected $PageGroups = []; // variable containing the number of pages of the groups

    protected $CurrPageGroup; // variable containing the alias of the current page group

    protected function IncludeJS($script)
    {
        $this->javascript = $script;
    }

    public function Footer()
    {
        $this->SetY(-8);
        if (count($this->PageGroups)) {
            $this->Cell(0, 6, 'Boleto ' . $this->GroupPageNo() . '/' . $this->PageGroupAlias(), 0, 0, 'C');
        }
    }

    protected function _putjavascript()
    {
        $this->_newobj();
        $this->n_js = $this->n;
        $this->_put('<<');
        $this->_put('/Names [(EmbeddedJS) ' . ($this->n + 1) . ' 0 R]');
        $this->_put('>>');
        $this->_put('endobj');
        $this->_newobj();
        $this->_put('<<');
        $this->_put('/S /JavaScript');
        $this->_put('/JS ' . $this->_textstring($this->javascript));
        $this->_put('>>');
        $this->_put('endobj');
    }

    public function _putresources()
    {
        parent::_putresources();
        if (! empty($this->javascript)) {
            $this->_putjavascript();
        }
    }

    public function _putcatalog()
    {
        parent::_putcatalog();
        if (! empty($this->javascript)) {
            $this->_put('/Names <</JavaScript ' . ($this->n_js) . ' 0 R>>');
        }
    }

    // create a new page group; call this before calling AddPage()
    public function StartPageGroup()
    {
        $this->NewPageGroup = true;
    }

    // current page in the group
    public function GroupPageNo()
    {
        return $this->PageGroups[$this->CurrPageGroup];
    }

    // alias of the current page group -- will be replaced by the total number of pages in this group
    public function PageGroupAlias()
    {
        return $this->CurrPageGroup;
    }

    public function _beginpage($orientation, $size, $rotation)
    {
        parent::_beginpage($orientation, $size, $rotation);
        if ($this->NewPageGroup) {
            // start a new group
            if (! is_array($this->PageGroups)) {
                $this->PageGroups = [];
            }
            $n = sizeof($this->PageGroups) + 1;
            $alias = '{' . $n . '}';
            $this->PageGroups[$alias] = 1;
            $this->CurrPageGroup = $alias;
            $this->NewPageGroup = false;
        } elseif ($this->CurrPageGroup) {
            $this->PageGroups[$this->CurrPageGroup]++;
        }
    }

    public function _putpages()
    {
        $nb = $this->page;
        if (! empty($this->PageGroups)) {
            // do page number replacement
            foreach ($this->PageGroups as $k => $v) {
                for ($n = 1; $n <= $nb; $n++) {
                    $this->pages[$n] = str_replace($k, $v, $this->pages[$n]);
                }
            }
        }
        parent::_putpages();
    }

    protected function _()
    {
        $args = func_get_args();
        $var = utf8_decode(array_shift($args));
        $s = vsprintf($var, $args);

        return $s;
    }

    /**
     * @param $w
     * @param $h
     * @param $txt
     * @param $border
     * @param $ln
     * @param $align
     * @param float $dec
     */
    protected function textFitCell($w, $h, $txt, $border, $ln, $align, $dec = 0.1)
    {
        $fsize = $this->FontSizePt;
        $size = $fsize;
        while ($this->GetStringWidth($txt) > ($w - 2)) {
            $this->SetFontSize($size -= $dec);
        }
        $this->Cell($w, $h, $txt, $border, $ln, $align);
        $this->SetFontSize($fsize);
    }

    /**
     * BarCode
     *
     * @param     $xpos
     * @param     $ypos
     * @param     $code
     * @param int $basewidth
     * @param int $height
     *
     * @throws Exception
     */
    public function i25($xpos, $ypos, $code, $basewidth = 1, $height = 10)
    {
        $code = (strlen($code) % 2 != 0 ? '0' : '') . $code;
        $wide = $basewidth;
        $narrow = $basewidth / 3;

        $barChar = [];
        // wide/narrow codes for the digits
        $barChar['0'] = 'nnwwn';
        $barChar['1'] = 'wnnnw';
        $barChar['2'] = 'nwnnw';
        $barChar['3'] = 'wwnnn';
        $barChar['4'] = 'nnwnw';
        $barChar['5'] = 'wnwnn';
        $barChar['6'] = 'nwwnn';
        $barChar['7'] = 'nnnww';
        $barChar['8'] = 'wnnwn';
        $barChar['9'] = 'nwnwn';
        $barChar['A'] = 'nn';
        $barChar['Z'] = 'wn';

        $this->SetFont('Arial', '', 10);
        $this->SetFillColor(0);

        // add start and stop codes
        $code = 'AA' . strtolower($code) . 'ZA';

        for ($i = 0; $i < strlen($code); $i = $i + 2) {
            // choose next pair of digits
            $charBar = $code[$i];
            $charSpace = $code[$i + 1];
            // check whether it is a valid digit
            if (! isset($barChar[$charBar])) {
                $this->Error('Invalid character in barcode: ' . $charBar);
            }
            if (! isset($barChar[$charSpace])) {
                $this->Error('Invalid character in barcode: ' . $charSpace);
            }
            // create a wide/narrow-sequence (first digit=bars, second digit=spaces)
            $seq = '';
            for ($s = 0; $s < strlen($barChar[$charBar]); $s++) {
                $seq .= $barChar[$charBar][$s] . $barChar[$charSpace][$s];
            }
            for ($bar = 0; $bar < strlen($seq); $bar++) {
                // set lineWidth depending on value
                if ($seq[$bar] == 'n') {
                    $lineWidth = $narrow;
                } else {
                    $lineWidth = $wide;
                }
                // draw every second value, because the second digit of the pair is represented by the spaces
                if ($bar % 2 == 0) {
                    $this->Rect($xpos, $ypos, $lineWidth, $height, 'F');
                }
                $xpos += $lineWidth;
            }
        }
    }

    public function __construct($orientation = 'P', $unit = 'mm', $size = 'A4')
    {
        parent::__construct($orientation, $unit, $size);
        $this->AliasNbPages('{1}');
    }

    /**
     * @param string $name
     * @param string $dest
     * I: send the file inline to the browser.<br>
     * D: send to the browser and force download.<br>
     * F: save to a local<br>
     * S: return as a string. name is ignored.
     * @return string
     */
    public function Output($name = '', $dest = 'I', $isUTF8 = false)
    {
        return parent::Output($name, $dest, $isUTF8);
    }
}
