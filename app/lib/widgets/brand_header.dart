import 'package:flutter/material.dart';
import 'package:flutter_svg/flutter_svg.dart';

TextStyle brandTitleStyle(BuildContext context) =>
    Theme.of(context).textTheme.titleLarge!.copyWith(fontWeight: FontWeight.w800, fontStyle: FontStyle.italic);

class BrandHeader extends StatelessWidget {
  final List<Widget> actions;
  final EdgeInsets padding;
  const BrandHeader({super.key, this.actions = const [], this.padding = const EdgeInsets.fromLTRB(16, 8, 16, 8)});

  @override
  Widget build(BuildContext context) {
    return SafeArea(
      bottom: false,
      child: Padding(
        padding: padding,
        child: Row(children: [
          // Try SVG logo; if missing, fallback to text brand
          SizedBox(
            height: 28,
            child: SvgPicture.asset(
              'assets/brand/logo.svg',
              fit: BoxFit.contain,
              colorFilter: const ColorFilter.mode(Colors.black, BlendMode.srcIn),
            ),
          ),
          const Spacer(),
          ...actions,
        ]),
      ),
    );
  }
}