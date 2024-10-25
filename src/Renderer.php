<?php

declare(strict_types=1);

namespace Calcagno\View;

use Closure;
use InvalidArgumentException;
use Throwable;

final class Renderer
{
  private string $viewDirectory;
  private string $fileExtension;
  private ?string $layout;
  private ?string $blockName = null;
  private array $blocks = [];
  private array $globalVars = [];
  private Closure $renderer;

  public function __construct(string $viewDirectory, string $fileExtension = 'php')
  {
    if (!is_dir($viewDirectory = str_replace('/', DIRECTORY_SEPARATOR, rtrim($viewDirectory, '\/')))) {
      throw new InvalidArgumentException(sprintf(
        'The specified view directory "%s" does not exost.',
        $viewDirectory
      ));
    }

    if ($fileExtension && $fileExtension === '.') {
      $fileExtension = ltrim($fileExtension, '.');
    }

    $this->viewDirectory = $viewDirectory;
    $this->fileExtension = $fileExtension;
    $this->renderer = function (): void {
      extract(func_get_arg(1), EXTR_OVERWRITE);
      require_once func_get_arg(0);
    };
  }

  public function addGlobalArray(array $data): void
  {
    foreach ($data as $name => $value) {
      if (!is_string($name)) {
        throw new InvalidArgumentException('The name attribute must be of type "s".');
      }
      $this->addGlobal($name, $value);
    }
  }

  public function addGlobal(string $name, mixed $value): void
  {
    if (array_key_exists($name, $this->globalVars)) {
      throw new InvalidArgumentException(sprintf(
        'Unable to add "%s" as this global variable has already been added.',
        $name
      ));
    }

    $this->globalVars[$name] = $value;
  }

  public function layout(string $layout): void
  {
    $this->layout = $layout;
  }

  public function block(string $name, string $content): void
  {
    if ($name === 'content') {
      throw new InvalidArgumentException('The block name "content" is reserved.');
    }

    if (!$name || array_key_exists($name, $this->blocks)) {
      return;
    }

    $this->blocks[$name] = $content;
  }

  public function beginBlock(string $name): void
  {
    if ($this->blockName) {
      throw new InvalidArgumentException('You cannot nest blocks within other blocks.');
    }

    $this->blockName = $name;
    ob_start();
  }

  public function endBlock(): void
  {
    if ($this->blockName === null) {
      throw new InvalidArgumentException('You must begin a block before can end it.');
    }

    $this->block($this->blockName, ob_get_clean());
    $this->blockName = null;
  }

  public function renderBlock(string $name, string $default = ''): string
  {
    return $this->blocks[$name] ?? $default;
  }

  public function render(string $view, array $params = []): string
  {
    $view = $this->viewDirectory . DIRECTORY_SEPARATOR . trim(str_replace('.', DIRECTORY_SEPARATOR, $view), '\/');

    if (pathinfo($view, PATHINFO_EXTENSION) === '') {
      $view .= ($this->fileExtension ? '.' . $this->fileExtension : '');
    }

    if (!file_exists($view) || !is_file($view)) {
      throw new InvalidArgumentException(sprintf(
        'View file "%s" does not exist or is not a file.',
        $view
      ));
    }

    $level = ob_get_level();
    $this->layout = null;
    ob_start();

    try {
      ($this->renderer)($view, $params + $this->globalVars);
      $content = ob_get_clean();
    } catch (Throwable $e) {
      while (ob_get_level() > $level) {
        ob_end_clean();
      }
      throw $e;
    }

    if (!$this->layout) {
      return $content;
    }

    $this->blocks['content'] = $content;
    return $this->render($this->layout);
  }

  public function esc(string $content): string
  {
    return htmlspecialchars($content, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', true);
  }

  public function display(string $view, array $data = []): void
  {
    echo $this->render($view, $data);
  }
}
