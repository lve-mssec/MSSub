<?php

declare(strict_types=1);

namespace App\Service\Import;

/**
 * Le compte rendu d'un import : ce qui passerait, ce qui passe, ce qui coince.
 *
 * Les erreurs portent le numéro de ligne du fichier, en comptant l'en-tête,
 * pour que l'opérateur retrouve la ligne fautive dans son tableur sans avoir à
 * recompter.
 */
final class ImportReport
{
    /** Ce qui empêche l'écriture. @var list<array{line: int, message: string, value: string}> */
    private array $errors = [];

    /**
     * Ce qui mérite d'être signalé sans rien bloquer — un statut inconnu, un
     * site absent. Bloquer un fichier de trois cents lignes pour une colonne
     * annexe obligerait à le corriger avant de pouvoir seulement le charger.
     *
     * @var list<array{line: int, message: string, value: string}>
     */
    private array $warnings = [];

    private int $created = 0;
    private int $updated = 0;
    private int $unchanged = 0;

    public function __construct(public readonly bool $dryRun)
    {
    }

    /*
     * Les incréments s'appellent addX et non X : un accesseur getX() et une
     * méthode X() qui coexistent se disputent la résolution d'attribut de Twig,
     * qui appelle la seconde et affiche du vide. Le compteur restait à l'écran
     * désespérément blanc.
     */

    public function addCreated(): void
    {
        ++$this->created;
    }

    public function addUpdated(): void
    {
        ++$this->updated;
    }

    public function addUnchanged(): void
    {
        ++$this->unchanged;
    }

    public function error(int $line, string $value, string $message): void
    {
        $this->errors[] = ['line' => $line, 'value' => $value, 'message' => $message];
    }

    public function warning(int $line, string $value, string $message): void
    {
        $this->warnings[] = ['line' => $line, 'value' => $value, 'message' => $message];
    }

    /** @return list<array{line: int, message: string, value: string}> */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    public function hasWarnings(): bool
    {
        return [] !== $this->warnings;
    }

    public function getCreated(): int
    {
        return $this->created;
    }

    public function getUpdated(): int
    {
        return $this->updated;
    }

    public function getUnchanged(): int
    {
        return $this->unchanged;
    }

    /** @return list<array{line: int, message: string, value: string}> */
    public function getErrors(): array
    {
        return $this->errors;
    }

    public function hasErrors(): bool
    {
        return [] !== $this->errors;
    }

    public function total(): int
    {
        return $this->created + $this->updated + $this->unchanged;
    }
}
