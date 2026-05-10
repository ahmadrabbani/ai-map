import unittest

from scripts.cad_semantics.layer_resolver import LayerResolver


class LayerNormalizationTest(unittest.TestCase):
    def setUp(self):
        self.standard_layers = {
            "Plot Boundary": {},
            "Boundary wall": {},
            "Plot line": {},
            "Front building line": {},
            "Side building line": {},
            "Rear Building line": {},
            "Section line": {},
            "Ramp": {},
            "Landscape": {},
            "Dimensions": {},
            "Measurement Text": {},
            "Text General": {},
            "External walls": {},
            "Internal walls": {},
            "Door": {},
            "Windows": {},
            "Ventilator": {},
            "Stairs": {},
            "Porch": {},
            "Services": {},
            "Mumty": {},
            "Water tank": {},
            "OTS Patio": {},
            "Parapet wall": {},
            "Column": {},
            "Beam": {},
            "Slab": {},
            "Foundation": {},
            "Hatch": {},
            "Pavement": {},
            "Rain Water tank": {},
            "Sewer line": {},
            "Terrace": {},
            "Solar": {},
            "Floor Level": {},
            "Extra": {},
            "Defpoints": {},
            "0": {},
        }
        self.resolver = LayerResolver(self.standard_layers, aliases={})

    def test_layer_with_number_prefix_matches_json_layer(self):
        result = self.resolver.resolve("1 Plot Boundary")
        self.assertEqual(result["standard_layer"], "Plot Boundary")
        self.assertEqual(result["match_type"], "numeric_prefix_removed")

    def test_layer_without_prefix_still_matches(self):
        result = self.resolver.resolve("Plot Boundary")
        self.assertEqual(result["standard_layer"], "Plot Boundary")

    def test_dot_prefix_matches(self):
        result = self.resolver.resolve("1. Plot Boundary")
        self.assertEqual(result["standard_layer"], "Plot Boundary")

    def test_dash_prefix_matches(self):
        result = self.resolver.resolve("1-Plot Boundary")
        self.assertEqual(result["standard_layer"], "Plot Boundary")

    def test_underscore_prefix_matches(self):
        result = self.resolver.resolve("1_Plot Boundary")
        self.assertEqual(result["standard_layer"], "Plot Boundary")

    def test_parenthesis_prefix_matches(self):
        result = self.resolver.resolve("1) Plot Boundary")
        self.assertEqual(result["standard_layer"], "Plot Boundary")

    def test_extra_spaces_match(self):
        result = self.resolver.resolve("  1  Plot   Boundary  ")
        self.assertEqual(result["standard_layer"], "Plot Boundary")

    def test_case_insensitive_match(self):
        result = self.resolver.resolve("1 plot boundary")
        self.assertEqual(result["standard_layer"], "Plot Boundary")

    def test_hyphen_and_underscore_tolerant_match(self):
        result1 = self.resolver.resolve("1 Plot-Boundary")
        result2 = self.resolver.resolve("1 Plot_Boundary")
        self.assertEqual(result1["standard_layer"], "Plot Boundary")
        self.assertEqual(result2["standard_layer"], "Plot Boundary")


if __name__ == "__main__":
    unittest.main()

