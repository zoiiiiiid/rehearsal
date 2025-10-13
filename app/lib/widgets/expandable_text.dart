// lib/widgets/expandable_text.dart
// Shared expandable/collapsible text used in feed captions and comments.

import 'package:flutter/material.dart';

class ExpandableText extends StatefulWidget {
  final String text;
  final int trimLength; // if length exceeds this, show toggle
  final int maxLines; // lines shown when collapsed
  final TextStyle? style;
  final String moreLabel;
  final String lessLabel;

  const ExpandableText(
    this.text, {
    super.key,
    this.trimLength = 140,
    this.maxLines = 3,
    this.style,
    this.moreLabel = 'See more..',
    this.lessLabel = 'See less',
  });

  @override
  State<ExpandableText> createState() => _ExpandableTextState();
}

class _ExpandableTextState extends State<ExpandableText> {
  bool _expanded = false;
  bool get _needsTrim => widget.text.trim().runes.length > widget.trimLength;

  @override
  Widget build(BuildContext context) {
    final body = widget.text.trim();
    final style = widget.style ?? const TextStyle(height: 1.25);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          body,
          maxLines: _expanded ? null : widget.maxLines,
          overflow: _expanded ? TextOverflow.visible : TextOverflow.ellipsis,
          softWrap: true,
          style: style,
        ),
        if (_needsTrim)
          Padding(
            padding: const EdgeInsets.only(top: 4),
            child: GestureDetector(
              behavior: HitTestBehavior.opaque,
              onTap: () => setState(() => _expanded = !_expanded),
              child: Text(
                _expanded ? widget.lessLabel : widget.moreLabel,
                style: const TextStyle(fontWeight: FontWeight.w600),
              ),
            ),
          ),
      ],
    );
  }
}
