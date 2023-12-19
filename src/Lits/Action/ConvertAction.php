<?php

declare(strict_types=1);

namespace Lits\Action;

use Lits\Exception\InvalidDataException;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Row;
use Psr\Http\Message\UploadedFileInterface as UploadedFile;
use Safe\Exceptions\PcreException;
use Slim\Exception\HttpInternalServerErrorException;
use Slim\Http\Response;
use Slim\Http\ServerRequest;

use function Safe\preg_match;
use function Safe\tempnam;

final class ConvertAction extends AuthAction
{
    /** @var array<string, string> */
    private array $columns = [];

    /** @throws HttpInternalServerErrorException */
    public function action(): void
    {
        try {
            $this->render($this->template());
        } catch (\Throwable $exception) {
            throw new HttpInternalServerErrorException(
                $this->request,
                null,
                $exception,
            );
        }
    }

    /**
     * @param array<string, string> $data
     * @throws HttpInternalServerErrorException
     */
    public function post(
        ServerRequest $request,
        Response $response,
        array $data,
    ): Response {
        $this->setup($request, $response, $data);

        try {
            $file = $this->file();
            $xml = $this->xml($file);
            $name = (string) $file->getClientFilename() . '.xml';

            return $this->downloadXml($xml, $name);
        } catch (InvalidDataException $exception) {
            $this->message(
                'failure',
                \rtrim($exception->getMessage(), '.') . '.',
            );
        }

        try {
            $this->redirect(
                $this->routeCollector->getRouteParser()->urlFor('convert'),
            );

            return $this->response;
        } catch (\Throwable $exception) {
            throw new HttpInternalServerErrorException(
                $this->request,
                null,
                $exception,
            );
        }
    }

    /** @throws HttpInternalServerErrorException */
    private function downloadXml(\DOMDocument $xml, string $name): Response
    {
        $xml->preserveWhiteSpace = false;
        $xml->formatOutput = true;

        $content = $xml->saveXML($xml->documentElement, \LIBXML_NOEMPTYTAG);

        if (!\is_string($content)) {
            throw new HttpInternalServerErrorException(
                $this->request,
                'Could not output XML file',
            );
        }

        try {
            $stream = $this->response->getBody();
            $stream->write($content);

            return $this->response
                ->withFileDownload($stream, $name, 'text/xml')
                ->withHeader('Cache-Control', 'max-age=0');
        } catch (\Throwable $exception) {
            throw new HttpInternalServerErrorException(
                $this->request,
                'Could not download file',
                $exception,
            );
        }
    }

    /**
     * @throws HttpInternalServerErrorException
     * @throws InvalidDataException
     */
    private function file(): UploadedFile
    {
        $files = $this->request->getUploadedFiles();

        if (
            !isset($files['file']) ||
            !($files['file'] instanceof UploadedFile)
        ) {
            throw new InvalidDataException('File was not uploaded');
        }

        if ($files['file']->getError() !== \UPLOAD_ERR_OK) {
            throw new HttpInternalServerErrorException(
                $this->request,
                'File upload failed with code ' .
                (string) $files['file']->getError(),
            );
        }

        return $files['file'];
    }

    /** @throws HttpInternalServerErrorException */
    private function xml(UploadedFile $file): \DOMDocument
    {
        try {
            $xml = new \DOMDocument('1.0', 'UTF-8');

            $assets = $xml->createElement('assets');
            $xml->appendChild($assets);

            $temp = tempnam(\sys_get_temp_dir(), 'zdam') . \pathinfo(
                (string) $file->getClientFilename(),
                \PATHINFO_EXTENSION,
            );

            $file->moveTo($temp);

            $spreadsheet = IOFactory::load($temp);
            $rows = $spreadsheet->getActiveSheet()->getRowIterator();

            foreach ($rows as $row) {
                $this->xmlRow($xml, $assets, $row);
            }
        } catch (\Throwable $exception) {
            throw new HttpInternalServerErrorException(
                $this->request,
                'Could not process uploaded file',
                $exception,
            );
        }

        return $xml;
    }

    /** @throws PcreException */
    private function xmlRow(
        \DOMDocument $xml,
        \DOMElement $assets,
        Row $row,
    ): void {
        $cells = $row->getCellIterator();
        $cells->setIterateOnlyExistingCells(false);

        if ($this->columns === []) {
            /** @psalm-var Cell $cell */
            foreach ($cells as $column => $cell) {
                $this->columns[$column] = (string) $cell->getValue();
            }

            return;
        }

        $asset = $xml->createElement('asset');
        $assets->appendChild($asset);

        $filename = $xml->createElement('filename');
        $asset->appendChild($filename);

        $metadata = $xml->createElement('metadata');
        $asset->appendChild($metadata);

        /** @psalm-var Cell $cell */
        foreach ($cells as $column => $cell) {
            $this->xmlColumnCell($xml, $filename, $metadata, $column, $cell);
        }
    }

    /** @throws PcreException */
    private function xmlColumnCell(
        \DOMDocument $xml,
        \DOMElement $filename,
        \DOMElement $metadata,
        string $column,
        Cell $cell,
    ): void {
        if ($this->columns[$column] === 'filename') {
            $filename->nodeValue = (string) $cell->getValue();

            return;
        }

        $preg = '/^(\d+)\s+(.*)$/';

        if (preg_match($preg, $this->columns[$column], $matches) === 0) {
            return;
        }

        $field = $xml->createElement('field', (string) $cell->getValue());
        $metadata->appendChild($field);

        if (isset($matches[1])) {
            $field->setAttribute('ref', $matches[1]);
        }

        if (isset($matches[2])) {
            $field->setAttribute('title', $matches[2]);
        }
    }
}
