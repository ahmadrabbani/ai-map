from __future__ import annotations

from abc import ABC, abstractmethod
from pathlib import Path
from typing import Any


class SourceParser(ABC):
    @abstractmethod
    def parse(self, path: str) -> Any:
        raise NotImplementedError


class DxfParser(SourceParser):
    def parse(self, path: str) -> Any:
        import ezdxf

        return ezdxf.readfile(str(path))


class PdfVectorParser(SourceParser):
    def parse(self, path: str) -> Any:
        raise NotImplementedError("PDF vector parser is not implemented yet.")


class PdfRasterParser(SourceParser):
    def parse(self, path: str) -> Any:
        raise NotImplementedError("PDF raster parser is not implemented yet.")
